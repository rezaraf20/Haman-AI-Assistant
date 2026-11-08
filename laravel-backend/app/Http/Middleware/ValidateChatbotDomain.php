<?php
namespace App\Http\Middleware;
use Closure; use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Support\DomainNormalizer;

// Validates against chatbot_index.primary_domain (public schema), not the
// tenant-schema chatbot_domains table the original version of this file used
// — a production data check found chatbot_domains has zero rows for every
// chatbot that exists; nothing in the app (admin panel, customer portal, or
// the WordPress plugin) ever writes to it. primary_domain is the field
// that's actually set — by the admin on ChatbotResource, or by the customer
// once at self-purchase time (BuyChatbot) — and is the real, populated
// source of truth today.
//
// One chatbot = one domain, strictly. A chatbot with no registered domain
// does not answer at all — it used to answer every site, which is how two
// unrelated sites ended up sharing one chatbot (and one quota, and one
// set of sync settings). Such a chatbot is "awaiting connection"
// (disabled_reason = awaiting_domain) until its owner registers the
// domain in the portal; TenantService::setChatbotActive() refuses to
// switch it on before that. Multi-domain is not supported, deliberately.
//
// Every refusal answers with a sentence the site owner can act on (in
// Persian and English — the visitor's language isn't known here) and a
// machine-readable `reason`; a request from an unregistered domain is
// also recorded (App\Support\DomainRejections) so the owner sees it in
// the portal and the platform sees it in the activity log.
//
// Real (browser fetch) widget requests always carry an Origin header — a
// browser refuses to let JS override or omit it on a cross-origin POST — so
// failing closed when it's missing costs nothing for legitimate traffic
// while blocking direct, non-browser API calls that spoof the header.
class ValidateChatbotDomain {
    public function handle(Request $request, Closure $next): mixed {
        $chatbotId = $request->input('chatbot_id');

        // TODO(remove after legacy plugin migration): the pre-1.2.0
        // WordPress plugin's /chat/message call never sent chatbot_id, only
        // conversation_id — this branch resolves it the old (slow) way, by
        // scanning every tenant_% schema, purely to keep those sites working
        // until they update. Acceptable cost at 5 tenants; not at 50. Once
        // the 'legacy plugin request' warning below stops appearing in the
        // log for a full billing cycle, delete this branch and make
        // chatbot_id required again in ChatController::sendMessage.
        if (!$chatbotId) {
            $conversationId = $request->input('conversation_id');
            if ($conversationId) {
                $chatbotId = $this->resolveChatbotIdFromConversation($conversationId);
            }
            if (!$chatbotId) {
                return response()->json(['error' => 'chatbot_id is required'], 422);
            }
            Log::warning('legacy plugin request', [
                'chatbot_id' => $chatbotId,
                'origin'     => $request->header('Origin'),
            ]);
        }

        // Origin is already just a host (no scheme/path/port) courtesy of
        // PHP_URL_HOST, but still needs the same www./case/trailing-slash
        // normalization as the stored value — normalize() is idempotent on
        // an already-bare host, so this is safe either way.
        $origin = DomainNormalizer::normalize(parse_url($request->header('Origin', ''), PHP_URL_HOST) ?: '');
        if (!$origin) {
            return response()->json(['error' => 'Origin header is required'], 403);
        }

        $index = DB::table('chatbot_index')->where('chatbot_id', $chatbotId)->first();
        $storedDomain = $index ? DomainNormalizer::normalize($index->primary_domain) : null;

        if (!$index) {
            return response()->json(['error' => 'Chatbot not found'], 404);
        }
        if (!$index->is_active) {
            // Suspended, expired, unverified: unchanged, a plain 404.
            return $index->disabled_reason === 'awaiting_domain'
                ? $this->refuse('awaiting_domain', 403)
                : response()->json(['error' => 'Chatbot not found'], 404);
        }
        if (!$storedDomain) {
            // Active with no domain cannot happen after the 2026_11_08
            // migration and setChatbotActive(); refused all the same.
            return $this->refuse('awaiting_domain', 403);
        }
        if ($storedDomain !== $origin) {
            \App\Support\DomainRejections::record($index, $origin);
            return $this->refuse('domain_not_registered', 403);
        }

        $request->attributes->set('chatbot_index', $index);
        return $next($request);
    }

    /** `error` stays a short code-like string for old clients; `message` is for people. */
    private function refuse(string $reason, int $status): \Illuminate\Http\JsonResponse {
        return response()->json([
            'error' => $reason === 'awaiting_domain' ? 'Chatbot awaiting connection' : 'Domain not authorized',
            'reason' => $reason,
            'message' => __("chatbot_domain.{$reason}", [], 'fa') . ' / ' . __("chatbot_domain.{$reason}", [], 'en'),
        ], $status);
    }

    private function resolveChatbotIdFromConversation(string $conversationId): ?string {
        $schemas = DB::select("SELECT schema_name FROM information_schema.schemata WHERE schema_name LIKE 'tenant_%'");
        foreach ($schemas as $s) {
            DB::statement("SET search_path TO {$s->schema_name}, public");
            $conv = DB::table('conversations')->where('id', $conversationId)->first();
            if ($conv) {
                DB::statement("SET search_path TO public");
                return $conv->chatbot_id;
            }
        }
        DB::statement("SET search_path TO public");
        return null;
    }
}
