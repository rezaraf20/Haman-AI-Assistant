<?php
namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * A shared, SSRF-hardened POST client — the PHP-side counterpart to
 * python-ai-service's safe_http.safe_post(), used for the exact same reason:
 * LiveQueryClient calls a tenant's own WordPress site (PaymentLinkService,
 * OrderStatusService — money and order data, on this side), and Phase 7's
 * custom action/tool builder will open a much bigger version of the same
 * surface. Anything that calls a merchant-configured or otherwise untrusted
 * hostname belongs on this, not a bare Http::post().
 *
 * What it does that Http::post() alone does not:
 *   - Resolves the hostname (A and AAAA) and validates every address it
 *     returns is not private, loopback, link-local (this is also where the
 *     169.254.169.254 cloud-metadata address falls), reserved, or otherwise
 *     unroutable — for both IPv4 and IPv6.
 *   - Pins the actual TCP connection to the one address that was validated
 *     (via cURL's CURLOPT_RESOLVE), so a second DNS lookup mid-request (DNS
 *     rebinding: the validation answer is safe, a later one for the same
 *     name is not) can never hand the connection a different, unvalidated
 *     address. The original hostname is still what's sent as the Host
 *     header and used for TLS SNI/certificate verification — only the
 *     address the socket actually connects to is pinned.
 *   - Re-resolves and re-validates every redirect hop the same way, up to a
 *     small cap, instead of following a validated request straight into an
 *     unvalidated redirect target.
 */
class SafeHttpClient
{
    const MAX_REDIRECTS = 3;
    const REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    /**
     * $resolver overrides how a hostname's candidate IPs are gathered —
     * tests only, real callers never pass it (the default is a real A/AAAA
     * DNS lookup, self::lookupHostIps()).
     *
     * @throws SafeHttpException refused before or during connection — never
     *   a signal to retry with less validation, only to give up or report
     *   the failure upward, same contract as an ordinary connection failure.
     */
    public static function post(string $url, string $body, array $headers, int $timeoutSeconds, ?callable $resolver = null): Response
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $parts  = parse_url($url);
            $scheme = $parts['scheme'] ?? null;
            $host   = $parts['host'] ?? null;

            if (!in_array($scheme, ['http', 'https'], true) || !$host) {
                throw new SafeHttpException("Unsupported or missing scheme/host in {$url}");
            }
            $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

            $ip = self::resolveSafeIp($host, $resolver);

            $response = Http::withOptions([
                'curl'            => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]],
                'allow_redirects' => false,
            ])->withBody($body, 'application/json')
                ->withHeaders($headers)
                ->timeout($timeoutSeconds)
                ->post($url);

            if (in_array($response->status(), self::REDIRECT_STATUSES, true) && $response->header('Location')) {
                $url = self::resolveRedirectUrl($url, $response->header('Location'));
                continue;
            }

            return $response;
        }

        throw new SafeHttpException('Too many redirects (> ' . self::MAX_REDIRECTS . ') for the original request');
    }

    /**
     * Every address this hostname resolves to (A and AAAA) must be safe —
     * refused outright the moment even one answer is blocked, since nothing
     * here controls which answer a real connection attempt would actually
     * use.
     */
    public static function resolveSafeIp(string $host, ?callable $resolver = null): string
    {
        if ($resolver === null && app()->environment('testing')) {
            // No real network is reachable in the test environment, and the
            // test suite already fakes the eventual HTTP call itself
            // (Http::fake() in PaymentLinkTest and friends) — a real DNS
            // lookup for a synthetic domain like shop.example.com would just
            // fail and break every one of those unrelated tests. Never
            // reached with APP_ENV=testing outside the suite (TestCase's own
            // production-safety guard refuses to run otherwise), and
            // SafeHttpClientTest tests the real validation logic directly by
            // always passing an explicit $resolver, which skips this branch.
            return '203.0.113.1'; // RFC 5737 TEST-NET-3 — never a real destination
        }

        $resolver ??= [self::class, 'lookupHostIps'];
        $ips = array_values(array_unique($resolver($host)));

        if (empty($ips)) {
            throw new SafeHttpException("DNS resolution failed for {$host}");
        }

        foreach ($ips as $ip) {
            if (self::isBlockedIp($ip)) {
                throw new SafeHttpException("{$host} resolves to a blocked address ({$ip})");
            }
        }

        // A deterministic pick among the (now all-safe) answers, so the
        // address validated above is the exact one pinned and connected to.
        sort($ips);
        return $ips[0];
    }

    /** Real A + AAAA lookup. A separate method purely so tests can swap it out via resolveSafeIp()'s $resolver. */
    public static function lookupHostIps(string $host): array
    {
        $ips = [];
        foreach (@dns_get_record($host, DNS_A) ?: [] as $record) {
            if (!empty($record['ip'])) $ips[] = $record['ip'];
        }
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (!empty($record['ipv6'])) $ips[] = $record['ipv6'];
        }
        return $ips;
    }

    /**
     * Byte-level, not string/regex-based: parses the address with
     * inet_pton so an IPv4-mapped IPv6 literal (::ffff:a.b.c.d) is judged
     * as the IPv4 address it actually is, rather than slipping through as
     * an unrecognized "public" IPv6 literal.
     *
     * FILTER_FLAG_NO_PRIV_RANGE + FILTER_FLAG_NO_RES_RANGE together exclude
     * private-use ranges (10/8, 172.16/12, 192.168/16, fc00::/7) and PHP's
     * "reserved" set (loopback 127/8 and ::1, link-local 169.254/16 and
     * fe80::/10 — the block that covers 169.254.169.254 — unspecified
     * 0.0.0.0/::, and more) — but NOT multicast/the rest of 224.0.0.0/4 and
     * ff00::/8, which are checked explicitly below.
     */
    public static function isBlockedIp(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) return true; // not a valid IP literal at all — fail closed

        if (strlen($packed) === 16 && substr($packed, 0, 10) === str_repeat("\x00", 10) && substr($packed, 10, 2) === "\xff\xff") {
            $ip = inet_ntop(substr($packed, 12, 4));
            $packed = @inet_pton($ip);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }

        $firstByte = ord($packed[0]);
        return strlen($packed) === 4 ? $firstByte >= 224 : $firstByte === 0xFF;
    }

    private static function resolveRedirectUrl(string $currentUrl, string $location): string
    {
        if (parse_url($location, PHP_URL_HOST)) {
            return $location; // already absolute
        }
        $base = parse_url($currentUrl);
        $scheme = $base['scheme'] ?? 'https';
        $host   = $base['host'] ?? '';
        $port   = isset($base['port']) ? ':' . $base['port'] : '';
        if (str_starts_with($location, '/')) {
            return "{$scheme}://{$host}{$port}{$location}";
        }
        $path = $base['path'] ?? '/';
        $dir  = rtrim(substr($path, 0, strrpos($path, '/') + 1), '/') ?: '';
        return "{$scheme}://{$host}{$port}{$dir}/{$location}";
    }
}

class SafeHttpException extends \RuntimeException {}
