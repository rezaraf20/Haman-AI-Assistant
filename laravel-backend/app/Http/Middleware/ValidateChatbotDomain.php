<?php
namespace App\Http\Middleware;
use Closure; use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
// while blocking direct, non-browser API calls that spoof the header. Same
// posture for a missing chatbot_id: 422, not "skip the check."
class ValidateChatbotDomain {
    public function handle(Request $request, Closure $next): mixed {
        $chatbotId = $request->input('chatbot_id');
        if (!$chatbotId) {
            return response()->json(['error' => 'chatbot_id is required'], 422);
        }

        $origin = parse_url($request->header('Origin', ''), PHP_URL_HOST);
        if (!$origin) {
            return response()->json(['error' => 'Origin header is required'], 403);
        }

        $index = DB::table('chatbot_index')->where('chatbot_id', $chatbotId)->where('is_active', true)->first();
        if (!$index) {
            return response()->json(['error' => 'Chatbot not found'], 404);
        }

        if ($index->primary_domain && $index->primary_domain !== $origin) {
            return response()->json(['error' => 'Domain not authorized'], 403);
        }

        $request->attributes->set('chatbot_index', $index);
        return $next($request);
    }
}
