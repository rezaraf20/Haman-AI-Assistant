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
// Enforcement is soft when primary_domain is unset (nullable — a chatbot
// created without one shouldn't be locked out of its own widget) and strict
// once it's set. Only a single domain is supported; if multi-domain per
// chatbot is ever needed, that's a real feature to build, not something to
// improvise here against a table nothing else uses.
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

        $index = DB::table('chatbot_index')->where('chatbot_id', $chatbotId)->where('is_active', true)->first();
        if (!$index) {
            return response()->json(['error' => 'Chatbot not found'], 404);
        }

        $storedDomain = DomainNormalizer::normalize($index->primary_domain);
        if ($storedDomain && $storedDomain !== $origin) {
            return response()->json(['error' => 'Domain not authorized'], 403);
        }

        $request->attributes->set('chatbot_index', $index);
        return $next($request);
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
