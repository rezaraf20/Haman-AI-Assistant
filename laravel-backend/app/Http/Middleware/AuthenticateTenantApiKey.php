<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use App\Models\ApiKey;
use App\Models\ChatbotIndexEntry;

class AuthenticateTenantApiKey {
    /** How stale last_used_at may get before it is written again. */
    private const TOUCH_THROTTLE_MINUTES = 5;

    public function handle(Request $request, Closure $next): mixed {
        $raw = $request->bearerToken();
        if (empty($raw) || !str_starts_with($raw, 'hfp_')) {
            return response()->json(['error' => 'Missing API key'], 401);
        }
        $prefix = substr($raw, 0, 12);
        $candidates = ApiKey::where('key_prefix', $prefix)
            ->where('is_active', true)
            ->with('tenant.plan')
            ->get();
        $apiKey = null;
        foreach ($candidates as $k) {
            if (password_verify($raw, $k->key_hash)) {
                $apiKey = $k;
                break;
            }
        }
        if (empty($apiKey)) return response()->json(['error' => 'Invalid API key'], 401);
        if (empty($apiKey->tenant)) return response()->json(['error' => 'Account error'], 403);
        if ($apiKey->isExpired()) return response()->json(['error' => 'API key expired'], 401);
        // One API key = one chatbot = one domain. A key not bound to a
        // chatbot could act on every chatbot of its tenant — and a bound key
        // used to be able to as well, because nothing compared the request's
        // chatbot with the key's. Both are refused, with a sentence the site
        // owner can act on.
        if (!$apiKey->chatbot_id) {
            return $this->refuse('api_key_unbound', 'API key not bound to a chatbot');
        }
        foreach ([$request->route('id'), $request->input('chatbot_id')] as $asked) {
            if ($asked !== null && $asked !== '' && (string) $asked !== (string) $apiKey->chatbot_id) {
                return $this->refuse('api_key_other_chatbot', 'API key belongs to another chatbot');
            }
        }
        $entry = ChatbotIndexEntry::find($apiKey->chatbot_id);
        // Awaiting a domain is not a suspension: the plugin may sync content
        // in the meantime; only the widget waits (ValidateChatbotDomain).
        if (!$entry || (!$entry->is_active && $entry->disabled_reason !== 'awaiting_domain')) {
            return response()->json(['error' => 'Chatbot suspended'], 403);
        }
        $request->attributes->set('api_chatbot_id', (string) $apiKey->chatbot_id);
        $this->touchLastUsed($apiKey, $request);

        app()->instance('current_tenant', $apiKey->tenant);

        // The plugin-api rate limiter keys on this. Without it the limiter
        // falls back to IP, and a shared host would put unrelated customers
        // in the same bucket — one busy shop throttling another.
        $request->attributes->set('api_key_id', (string) $apiKey->id);

        return $next($request);
    }

    private function refuse(string $reason, string $error): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            // `error` is what the plugin displays; it gets the full sentence.
            'error' => __("chatbot_domain.{$reason}", [], 'fa') . ' / ' . __("chatbot_domain.{$reason}", [], 'en'),
            'reason' => $reason,
            'code' => $error,
        ], 403);
    }

    /**
     * last_used_at is what CustomerOnboarding reads to decide whether the
     * plugin is installed. Nothing wrote it, so that checklist told every
     * customer their plugin was not installed forever — including the ones
     * whose plugin was sitting right there syncing.
     *
     * Throttled rather than written on every request: a busy plugin can
     * call this endpoint constantly, and an UPDATE per request would add a
     * write to a hot path to keep a column that only needs minute-level
     * accuracy. updateQuietly() skips model events for the same reason.
     */
    private function touchLastUsed(ApiKey $apiKey, Request $request): void
    {
        if ($apiKey->last_used_at && $apiKey->last_used_at->gt(now()->subMinutes(self::TOUCH_THROTTLE_MINUTES))) {
            return;
        }

        try {
            $apiKey->updateQuietly([
                'last_used_at' => now(),
                'last_used_ip' => $request->ip(),
            ]);
        } catch (\Throwable $e) {
            // Recording usage must never break a working API call.
        }
    }
}