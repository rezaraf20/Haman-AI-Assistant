<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\{ApiKey, ChatbotIndexEntry, ConnectionTest};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Str;

/**
 * "Test Connection" — deliberately NOT behind the shared auth.apikey
 * middleware (see AuthenticateTenantApiKey): that middleware returns its
 * error response and stops, before any controller ever runs, so nothing
 * downstream of it could ever log a failed attempt. This duplicates its
 * checks (precise reason codes a merchant's plugin can act on, not just
 * "unauthorized") specifically so every branch — success or a named
 * failure — gets written to connection_tests before responding.
 *
 * What this can NEVER see: a request that never arrives at all (DNS
 * failure, firewall, wrong base_url typed into the plugin settings, the
 * site itself unreachable) — those are only ever observable client-side,
 * in the plugin's own wp_remote_request() result. The plugin is
 * responsible for surfacing that case; this endpoint only speaks for
 * requests that actually reached the server.
 */
class ConnectionTestController extends Controller
{
    public function test(Request $request): JsonResponse
    {
        $ip = $request->ip();
        $userAgent = Str::limit((string) $request->userAgent(), 250, '');
        $raw = $request->bearerToken();

        if (empty($raw) || !str_starts_with($raw, 'hfp_')) {
            return $this->respond(null, null, null, 'missing_key', 'No API key was sent with the request.', $ip, $userAgent, 401);
        }

        $prefix = substr($raw, 0, 12);
        $candidates = ApiKey::where('key_prefix', $prefix)->where('is_active', true)->with('tenant')->get();
        $apiKey = null;
        foreach ($candidates as $k) {
            if (password_verify($raw, $k->key_hash)) {
                $apiKey = $k;
                break;
            }
        }

        if (!$apiKey) {
            return $this->respond(null, null, null, 'invalid_key', 'This API key does not match any active key on the platform — it may have been regenerated.', $ip, $userAgent, 401);
        }
        if (!$apiKey->tenant) {
            return $this->respond(null, null, $apiKey->id, 'account_error', "This key's tenant account could not be found.", $ip, $userAgent, 403);
        }
        if ($apiKey->isExpired()) {
            return $this->respond($apiKey->tenant_id, $apiKey->chatbot_id, $apiKey->id, 'key_expired', 'This API key has expired.', $ip, $userAgent, 401);
        }

        $entry = $apiKey->chatbot_id ? ChatbotIndexEntry::find($apiKey->chatbot_id) : null;
        if ($apiKey->chatbot_id && (!$entry || !$entry->is_active)) {
            return $this->respond($apiKey->tenant_id, $apiKey->chatbot_id, $apiKey->id, 'chatbot_suspended', 'This chatbot is suspended — check your plan/trial status in the portal.', $ip, $userAgent, 403);
        }

        // A real success: the same throttled touch AuthenticateTenantApiKey
        // does, so clicking "Test Connection" counts as real plugin
        // activity for CustomerOnboarding too, not just a diagnostic no-op.
        $apiKey->updateQuietly(['last_used_at' => now(), 'last_used_ip' => $ip]);

        return $this->respond(
            $apiKey->tenant_id, $apiKey->chatbot_id, $apiKey->id, 'success',
            'Connected' . ($entry?->name ? " as '{$entry->name}'" : ''),
            $ip, $userAgent, 200, ['chatbot_name' => $entry?->name ?? $apiKey->tenant->name],
        );
    }

    private function respond(
        ?string $tenantId, ?string $chatbotId, ?string $apiKeyId,
        string $outcome, string $message, string $ip, string $userAgent, int $status,
        array $extra = [],
    ): JsonResponse {
        try {
            ConnectionTest::create([
                'tenant_id' => $tenantId, 'chatbot_id' => $chatbotId, 'api_key_id' => $apiKeyId,
                'outcome' => $outcome, 'message' => $message, 'ip' => $ip, 'user_agent' => $userAgent,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // A logging failure must never hide the real result from the
            // merchant clicking the button right now.
            report($e);
        }

        return response()->json(array_merge([
            'ok' => $outcome === 'success',
            'reason' => $outcome,
            'message' => $message,
        ], $extra), $status);
    }
}
