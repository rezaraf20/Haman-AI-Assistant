<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * One HMAC-signed call into a tenant's own WordPress plugin live-query
 * endpoint. Shared by the Laravel-side features that need live store data
 * without involving the AI service at all (PaymentLinkService,
 * OrderStatusService) — Python's product_tools.py reaches the same
 * endpoint with its own copy of this scheme, since it resolves the secret
 * differently (a public-schema join rather than Tenant::getWebhookSecret()).
 *
 * Signature scheme: X-Hamman-Signature: sha256=hex(hmac_sha256(raw_body,
 * secret)) — see Hamman_Live_Query_Handler::verify_signature().
 */
class LiveQueryClient
{
    const TIMEOUT_SECONDS = 5;

    /**
     * Returns null on ANY failure — unreachable site, bad signature,
     * non-2xx, non-array body. Callers must treat null as "could not
     * confirm", never as an empty/negative answer, since those mean very
     * different things for stock, money and order privacy alike.
     */
    public function call(string $domain, string $secret, string $action, array $params): ?array
    {
        $body = json_encode(array_merge(['action' => $action], $params), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) return null;
        $signature = 'sha256=' . hash_hmac('sha256', $body, $secret);

        try {
            $response = Http::withBody($body, 'application/json')
                ->withHeaders(['X-Hamman-Signature' => $signature])
                ->timeout(self::TIMEOUT_SECONDS)
                ->post("https://{$domain}/wp-json/hamman/v1/live-query");
        } catch (\Throwable $e) {
            return null;
        }

        if (!$response->successful()) return null;
        $data = $response->json();
        return is_array($data) ? $data : null;
    }
}
