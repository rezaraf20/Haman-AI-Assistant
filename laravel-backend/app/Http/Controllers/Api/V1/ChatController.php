<?php
namespace App\Http\Controllers\Api\V1;

use App\Services\ChatService;
use App\Models\Tenant\{Chatbot, Conversation};
use Illuminate\Http\{Request, JsonResponse};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

class ChatController extends BaseApiController
{
    public function __construct(private ChatService $svc) {}

    private function setSchemaFromChatbot(string $chatbotId): ?object
    {
        $index = DB::table('chatbot_index')
            ->where('chatbot_id', $chatbotId)
            ->where('is_active', true)
            ->first();
        if (!$index) return null;
        DB::statement("SET search_path TO {$index->schema_name}, public");
        $tenant = \App\Models\Tenant::find($index->tenant_id);
        if ($tenant) app()->instance('current_tenant', $tenant);
        return $index;
    }

    private function findConversation(string $convId, string $sessionId): ?Conversation
    {
        $schemas = DB::select("SELECT schema_name FROM information_schema.schemata WHERE schema_name LIKE 'tenant_%'");
        foreach ($schemas as $s) {
            DB::statement("SET search_path TO {$s->schema_name}, public");
            $conv = Conversation::where('id', $convId)->where('session_id', $sessionId)->first();
            if ($conv) return $conv;
        }
        return null;
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

        $index = $this->setSchemaFromChatbot($d['chatbot_id']);
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
                'widget_config'   => $chatbot->widget_config,
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

    public function sendMessage(Request $req): JsonResponse
    {
        $d = $req->validate([
            'conversation_id' => 'required|uuid',
            'message'         => 'required|string|min:1|max:2000',
            'session_id'      => 'required|string|max:128',
        ]);

        $conv = $this->findConversation($d['conversation_id'], $d['session_id']);
        if (!$conv) return $this->notFound('Conversation not found');

        $index = $this->setSchemaFromChatbot($conv->chatbot_id);
        if (!$index) return $this->notFound('Chatbot not found');

        $chatbot   = Chatbot::findOrFail($conv->chatbot_id);
        $maxMsgs   = $chatbot->widget_config['rate_limit_max_messages'] ?? null;
        $blockMins = $chatbot->widget_config['rate_limit_block_minutes'] ?? null;
        if ($maxMsgs && $blockMins) {
            $key = 'hamman-chat:' . $conv->chatbot_id . ':' . $req->ip();
            if (RateLimiter::tooManyAttempts($key, (int) $maxMsgs)) {
                return $this->tooManyRequests('تعداد پیام‌های مجاز شما به پایان رسیده. لطفاً چند دقیقه دیگر دوباره امتحان کنید.', RateLimiter::availableIn($key));
            }
            RateLimiter::hit($key, (int) $blockMins * 60);
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

    public function submitFeedback(Request $req): JsonResponse
    {
        $req->validate(['message_id' => 'required|uuid', 'rating' => 'required|in:1,-1']);
        return $this->ok(['message' => 'Feedback recorded']);
    }
}