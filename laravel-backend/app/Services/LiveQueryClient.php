<?php
namespace App\Services;

use App\Support\PluginLegacy;
use Illuminate\Support\Facades\Http;

/**
 * One HMAC-signed call into a tenant's own WordPress plugin live-query
 * endpoint. Shared by the Laravel-side features that need live store data
 * without involving the AI service at all (PaymentLinkService,
 * OrderStatusService) — Python's product_tools.py reaches the same
 * endpoint with its own copy of this scheme, since it resolves the secret
 * differently (a public-schema join rather than Tenant::getWebhookSecret()).
 *
 * Signature scheme: X-Haman-Signature: sha256=hex(hmac_sha256(raw_body,
 * secret)) — see Haman_Live_Query_Handler::verify_signature().
 */
class LiveQueryClient
{
    const TIMEOUT_SECONDS = 5;

    /**
     * https for anything that could be a real shop; http only where it
     * provably could not be.
     *
     * Must stay identical to product_tools.py's _scheme(): the two sides
     * reach the same endpoint and a disagreement about the scheme means one
     * of them silently stops working. Deliberately narrow — a bare hostname
     * with no dot, a private-use TLD, or a private/loopback IPv4. A public
     * domain can never be downgraded, so a hijacked primary_domain cannot
     * become a way to strip TLS off every live query.
     */
    public static function scheme(string $domain): string
    {
        $host = strtolower(explode(':', explode('/', $domain)[0])[0]);

        if (!str_contains($host, '.')) return 'http';

        foreach (['.test', '.local', '.localhost', '.internal', '.invalid'] as $suffix) {
            if (str_ends_with($host, $suffix)) return 'http';
        }

        if (preg_match('/^(10|127)\.\d+\.\d+\.\d+$/', $host)) return 'http';
        if (preg_match('/^192\.168\.\d+\.\d+$/', $host)) return 'http';
        if (preg_match('/^172\.(1[6-9]|2\d|3[01])\.\d+\.\d+$/', $host)) return 'http';

        return 'https';
    }

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

        // A site still on the 1.x plugin only answers the old namespace, and
        // a 404 is how that looks. Any other failure is a real one and is not
        // retried -- see PluginLegacy for when this stops.
        $response = null;
        foreach (PluginLegacy::namespaces() as $namespace) {
            try {
                $response = Http::withBody($body, 'application/json')
                    ->withHeaders(['X-Haman-Signature' => $signature])
                    ->timeout(self::TIMEOUT_SECONDS)
                    ->post(self::scheme($domain) . "://{$domain}/wp-json/{$namespace}/live-query");
            } catch (\Throwable $e) {
                return null;
            }

            if ($response->status() !== 404) break;
        }

        if ($response === null || !$response->successful()) return null;
        $data = $response->json();
        return is_array($data) ? $data : null;
    }
}
