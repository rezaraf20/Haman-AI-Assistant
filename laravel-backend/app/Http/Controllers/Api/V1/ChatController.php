<?php
namespace App\Http\Controllers\Api\V1;

use App\Services\ChatService;
use App\Models\Tenant\{Chatbot, Conversation};
use Illuminate\Http\{Request, JsonResponse};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use App\Support\WidgetDefaults;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatController extends BaseApiController
{
    public function __construct(private ChatService $svc) {}

    // $preResolved lets callers reuse the chatbot_index row
    // ValidateChatbotDomain already looked up and stashed on the request
    // (see $request->attributes->get('chatbot_index')) instead of querying
    // it again here.
    private function setSchemaFromChatbot(string $chatbotId, ?object $preResolved = null): ?object
    {
        $index = $preResolved ?? DB::table('chatbot_index')
            ->where('chatbot_id', $chatbotId)
            ->where('is_active', true)
            ->first();
        if (!$index) return null;
        DB::statement("SET search_path TO {$index->schema_name}, public");
        $tenant = \App\Models\Tenant::find($index->tenant_id);
        if ($tenant) app()->instance('current_tenant', $tenant);
        return $index;
    }

    // Widget UI text (send button, placeholder, error messages) defaults per
    // the chatbot's own `language` column — see App\Support\WidgetDefaults —
    // with anything the admin explicitly set via updateWidgetSettings()
    // taking priority. The WordPress plugin applies this once it receives it;
    // it has its own get_locale()-based fallback for the very first paint,
    // before this response exists.
    private function mergedWidgetConfig(Chatbot $chatbot): array {
        return array_merge(WidgetDefaults::forLanguage($chatbot->language), $chatbot->widget_config ?? []);
    }

    public function createSession(Request $req): JsonResponse
    {
        $d = $req->validate([
            'chatbot_id' => 'required|uuid',
            'session_id' => 'required|string|max:128',
            'visitor_id' => 'nullable|string|max:128',
            'page_url'   => 'nullable|string|max:2000',
            'language'   => 'nullable|string|max:10',
        ]);

        $index = $this->setSchemaFromChatbot($d['chatbot_id'], $req->attributes->get('chatbot_index'));
        if (!$index) return $this->notFound('Chatbot not found');

        $chatbot = Chatbot::where('id', $d['chatbot_id'])->where('is_active', true)->first();
        if (!$chatbot) return $this->notFound('Chatbot not found or inactive');

        $existing = Conversation::where('chatbot_id', $d['chatbot_id'])
            ->where('session_id', $d['session_id'])
            ->first();
        if ($existing) {
            return $this->ok([
                'conversation_id' => $existing->id,
                'session_id'      => $existing->session_id,
                'welcome_message' => $chatbot->welcome_message,
                'language'        => $chatbot->language,
                'widget_config'   => $this->mergedWidgetConfig($chatbot),
            ]);
        }

        $ua   = $req->userAgent() ?? '';
        $conv = Conversation::create([
            'chatbot_id'  => $d['chatbot_id'],
            'session_id'  => $d['session_id'],
            'visitor_id'  => $d['visitor_id'] ?? null,
            'page_url'    => $d['page_url'] ?? null,
            'language'    => $d['language'] ?? 'en',
            'device_type' => preg_match('/Mobile|Android|iPhone/i', $ua) ? 'mobile' : 'desktop',
            'status'      => 'active',
            'started_at'  => now(),
        ]);

        return $this->created([
            'conversation_id' => $conv->id,
            'session_id'      => $conv->session_id,
            'welcome_message' => $chatbot->welcome_message,
            'language'        => $chatbot->language,
            'widget_config'   => $chatbot->widget_config,
        ]);
    }

    public function sendMessage(Request $req): JsonResponse|StreamedResponse
    {
        // TODO(remove after legacy plugin migration): chatbot_id is nullable
        // here — not required|uuid — only because the pre-1.2.0 WordPress
        // plugin's /chat/message call never sent it, only conversation_id.
        // ValidateChatbotDomain resolves it from conversation_id in that case
        // (see the 'legacy plugin request' warning it logs) and stashes the
        // result on $request->attributes. Once that warning stops appearing
        // in the logs for a full billing cycle, every site is on >=1.2.0:
        // flip this back to 'required|uuid' and delete the fallback branch
        // in ValidateChatbotDomain.
        $d = $req->validate([
            'chatbot_id'      => 'nullable|uuid',
            'conversation_id' => 'required|uuid',
            'message'         => 'required|string|min:1|max:2000',
            'session_id'      => 'required|string|max:128',
        ]);

        $preResolved = $req->attributes->get('chatbot_index');
        $chatbotId = $d['chatbot_id'] ?? ($preResolved->chatbot_id ?? null);
        if (!$chatbotId) return $this->notFound('Chatbot not found');

        // chatbot_id (now resolved one way or another) lets us set the right
        // schema directly instead of scanning every tenant_% schema for a
        // matching conversation row — that scan was an unbounded per-message
        // query fan-out across every tenant.
        $index = $this->setSchemaFromChatbot($chatbotId, $preResolved);
        if (!$index) return $this->notFound('Chatbot not found');

        $conv = Conversation::where('id', $d['conversation_id'])
            ->where('session_id', $d['session_id'])
            ->where('chatbot_id', $chatbotId)
            ->first();
        if (!$conv) return $this->notFound('Conversation not found');

        $chatbot   = Chatbot::findOrFail($conv->chatbot_id);
        $maxMsgs   = $chatbot->widget_config['rate_limit_max_messages'] ?? null;
        $blockMins = $chatbot->widget_config['rate_limit_block_minutes'] ?? null;
        if ($maxMsgs && $blockMins) {
            $key = 'hamman-chat:' . $conv->chatbot_id . ':' . $req->ip();
            if (RateLimiter::tooManyAttempts($key, (int) $maxMsgs)) {
                $rateLimitMessage = $chatbot->widget_config['rate_limit_message']
                    ?? ($chatbot->language === 'en'
                        ? "You've reached the message limit. Please try again in a few minutes."
                        : 'تعداد پیام‌های مجاز شما به پایان رسیده. لطفاً چند دقیقه دیگر دوباره امتحان کنید.'); // i18n:widget
                return $this->tooManyRequests($rateLimitMessage, RateLimiter::availableIn($key));
            }
            RateLimiter::hit($key, (int) $blockMins * 60);
        }

        // Strict literal match, not $req->accepts('text/event-stream') —
        // that helper also matches a plain "*/*" Accept header, which is
        // what curl and older/legacy widget builds send by default. Only a
        // client that explicitly names text/event-stream gets a stream;
        // every other request — including every pre-existing WordPress
        // plugin install — gets the exact same single JSON response as
        // before this feature existed.
        if (str_contains($req->header('Accept', ''), 'text/event-stream')) {
            return $this->streamMessage($conv, $d['message']);
        }

        $r   = $this->svc->sendMessage($conv, $d['message']);
        $msg = $r['message'];

        return $this->ok([
            'message_id'  => $msg->id,
            'response'    => $msg->content,
            'model'       => $msg->model_used,
            'latency_ms'  => $msg->latency_ms,
            'is_fallback' => $msg->is_fallback,
            'sources'     => $r['result']['sources'] ?? [],
        ]);
    }

    private function streamMessage(Conversation $conv, string $message): StreamedResponse
    {
        return response()->stream(function () use ($conv, $message) {
            $write = function (string $chunk) {
                echo $chunk;
                if (ob_get_level() > 0) { @ob_flush(); }
                flush();
            };

            try {
                $r = $this->svc->sendMessageStream($conv, $message, function (string $delta) use ($write) {
                    $write('data: ' . json_encode(['delta' => $delta], JSON_UNESCAPED_UNICODE) . "\n\n");
                });
                $msg = $r['message'];
                $write('event: done' . "\n" . 'data: ' . json_encode([
                    'message_id'  => $msg->id,
                    'model'       => $msg->model_used,
                    'latency_ms'  => $msg->latency_ms,
                    'is_fallback' => $msg->is_fallback,
                    'sources'     => $r['result']['sources'] ?? [],
                ], JSON_UNESCAPED_UNICODE) . "\n\n");
            } catch (\Throwable $e) {
                report($e);
                $write('event: error' . "\n" . 'data: ' . json_encode(['error' => 'stream_failed']) . "\n\n");
            }
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection'        => 'keep-alive',
        ]);
    }

    public function history(Request $req, string $sessionId): JsonResponse
    {
        $chatbotId = $req->query('chatbot_id');
        if (!$chatbotId) return $this->notFound('chatbot_id required');
        $index = $this->setSchemaFromChatbot($chatbotId);
        if (!$index) return $this->notFound('Chatbot not found');
        $conv = Conversation::where('session_id', $sessionId)->first();
        if (!$conv) return $this->notFound('Session not found');
        return $this->ok([
            'conversation_id' => $conv->id,
            'messages'        => $conv->messages()->get(['id', 'role', 'content', 'created_at']),
        ]);
    }

    // Companion to history() above, keyed by conversation_id instead of
    // session_id — what the WordPress widget uses to restore a conversation
    // it persisted in localStorage across a page reload, when it no longer
    // has (or trusts) the original session_id's in-memory state.
    public function conversationMessages(Request $req, string $conversationId): JsonResponse
    {
        $chatbotId = $req->query('chatbot_id');
        if (!$chatbotId) return $this->notFound('chatbot_id required');
        $index = $this->setSchemaFromChatbot($chatbotId, $req->attributes->get('chatbot_index'));
        if (!$index) return $this->notFound('Chatbot not found');
        $conv = Conversation::where('id', $conversationId)->where('chatbot_id', $chatbotId)->first();
        if (!$conv) return $this->notFound('Conversation not found');
        return $this->ok([
            'conversation_id' => $conv->id,
            'messages'        => $conv->messages()->get(['id', 'role', 'content', 'created_at']),
        ]);
    }

    public function submitFeedback(Request $req): JsonResponse
    {
        $req->validate(['message_id' => 'required|uuid', 'rating' => 'required|in:1,-1']);
        return $this->ok(['message' => 'Feedback recorded']);
    }
}