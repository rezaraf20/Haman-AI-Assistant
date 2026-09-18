<?php
namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * How long the TLS certificates have left.
 *
 * A certificate is the one part of this platform that breaks on a schedule
 * rather than in response to anything anyone did. Let's Encrypt issues for 90
 * days, DirectAdmin renews automatically, and when that renewal quietly stops
 * working nothing looks wrong for weeks -- then every browser and every widget
 * on every customer's site refuses to connect on the same morning.
 *
 * Two real failures on this platform were exactly that shape, both found by
 * hand rather than by anything watching:
 *
 *   - A vhost Alias pointed the ACME challenge path at a docroot instead of
 *     the central directory DirectAdmin writes tokens into, so http-01 would
 *     have failed at the next renewal with nothing failing in the meantime.
 *   - A renewal succeeded and wrote a new certificate to disk, but nothing
 *     reloaded Apache, so the old one kept being served. Disk said 89 days,
 *     the wire said 62.
 *
 * The second is why this reads the certificate off the wire, over a real TLS
 * connection to the public hostname, and not from a file. What is served is
 * the only thing that matters to a visitor.
 *
 * Verification is deliberately off. An expired or mismatched certificate must
 * still report its dates -- that is precisely the case worth alerting on, and
 * a strict handshake would throw instead of telling us why.
 */
class CertificateExpiry
{
    /** Warn below this. Renewal begins around 30 days, so 21 means it has already missed twice. */
    public const WARN_DAYS = 21;

    private const CACHE_KEY = 'certificates.expiry';
    private const CACHE_SECONDS = 21600;   // six hours; this moves once a day at most
    private const TIMEOUT = 8;

    /**
     * Every hostname the platform answers on, deduplicated.
     *
     * api_public is included as a host in its own right: it is the address
     * baked into every installed widget, so its certificate matters more than
     * any of the others, not less.
     */
    public static function hosts(): array
    {
        $hosts = [
            (string) config('haman.domains.landing'),
            (string) config('haman.domains.app'),
            (string) config('haman.domains.api'),
            (string) parse_url((string) config('haman.domains.api_public'), PHP_URL_HOST),
        ];

        return array_values(array_unique(array_filter($hosts)));
    }

    /** All hosts, cached. Pass true to ignore the cache. */
    public static function all(bool $fresh = false): array
    {
        if ($fresh) Cache::forget(self::CACHE_KEY);

        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, function () {
            return array_map(fn ($host) => self::check($host), self::hosts());
        });
    }

    /** Only the ones worth acting on: expiring soon, or unreadable. */
    public static function failing(): array
    {
        return array_values(array_filter(
            self::all(true),
            fn ($c) => !$c['ok'] || $c['days'] === null || $c['days'] < self::WARN_DAYS,
        ));
    }

    /**
     * One host, read from the live connection.
     *
     * @return array{host:string, days:?int, expires_at:?string, ok:bool, error:?string}
     */
    public static function check(string $host): array
    {
        // Built per call, never with the "+" union operator: on a duplicate
        // key "+" keeps the LEFT value, so unioning a default row with an
        // error message quietly threw the message away and reported a failure
        // with no reason given -- the exact silence this class exists to stop.
        $failed = fn (string $error) => [
            'host' => $host, 'days' => null, 'expires_at' => null,
            'ok' => false, 'error' => $error,
        ];

        try {
            $context = stream_context_create(['ssl' => [
                'capture_peer_cert' => true,
                // See the class comment: an invalid certificate is the thing
                // being looked for, so the handshake must not refuse it.
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'SNI_enabled'       => true,
                'peer_name'         => $host,
            ]]);

            $socket = @stream_socket_client(
                "ssl://{$host}:443", $errno, $error, self::TIMEOUT,
                STREAM_CLIENT_CONNECT, $context,
            );

            if ($socket === false) {
                return $failed($error ?: "could not connect to {$host}");
            }

            $params = stream_context_get_params($socket);
            fclose($socket);

            $peer = $params['options']['ssl']['peer_certificate'] ?? null;
            if (!$peer) return $failed('no certificate presented');

            $parsed = openssl_x509_parse($peer);
            $validTo = $parsed['validTo_time_t'] ?? null;
            if (!$validTo) return $failed('certificate has no expiry date');

            $days = (int) floor(($validTo - time()) / 86400);

            return [
                'host'       => $host,
                'days'       => $days,
                'expires_at' => date('Y-m-d H:i', $validTo),
                'ok'         => $days >= self::WARN_DAYS,
                'error'      => null,
            ];
        } catch (\Throwable $e) {
            Log::warning("Certificate check failed for {$host}: {$e->getMessage()}");

            return $failed($e->getMessage());
        }
    }
}
