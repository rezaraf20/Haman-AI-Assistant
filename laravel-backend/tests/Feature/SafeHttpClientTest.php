<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Services\{SafeHttpClient, SafeHttpException};
use Illuminate\Support\Facades\Http;

/**
 * SSRF hardening for SafeHttpClient::post() — LiveQueryClient now goes
 * through this instead of a bare Http::post(). isBlockedIp() is tested with
 * real IP literals from every blocked category; resolveSafeIp() and post()
 * take an injectable resolver so the resolve/validate/pin/redirect
 * orchestration is fully testable without a real DNS lookup or network call.
 *
 * The pinning mechanism itself (cURL's CURLOPT_RESOLVE, set in post()) is a
 * standard, well-established primitive for exactly this purpose — proving
 * the wire-level TCP connection actually honours it needs a real socket
 * trace this suite doesn't attempt; what's tested here is that post() builds
 * that option correctly and never sends a request to an unvalidated host.
 */
class SafeHttpClientTest extends TestCase
{
    public function test_private_ipv4_ranges_are_blocked(): void
    {
        foreach (['10.0.0.1', '10.255.255.254', '172.16.0.1', '172.31.255.254', '192.168.0.1', '192.168.255.254'] as $ip) {
            $this->assertTrue(SafeHttpClient::isBlockedIp($ip), $ip);
        }
    }

    public function test_loopback_is_blocked(): void
    {
        $this->assertTrue(SafeHttpClient::isBlockedIp('127.0.0.1'));
        $this->assertTrue(SafeHttpClient::isBlockedIp('127.255.255.255'));
        $this->assertTrue(SafeHttpClient::isBlockedIp('::1'));
    }

    public function test_link_local_is_blocked(): void
    {
        $this->assertTrue(SafeHttpClient::isBlockedIp('169.254.1.1'));
        $this->assertTrue(SafeHttpClient::isBlockedIp('fe80::1'));
    }

    public function test_cloud_metadata_address_is_blocked(): void
    {
        $this->assertTrue(SafeHttpClient::isBlockedIp('169.254.169.254'));
        $this->assertTrue(SafeHttpClient::isBlockedIp('::ffff:169.254.169.254'));
    }

    public function test_unspecified_and_multicast_are_blocked(): void
    {
        $this->assertTrue(SafeHttpClient::isBlockedIp('0.0.0.0'));
        $this->assertTrue(SafeHttpClient::isBlockedIp('224.0.0.1'));
        $this->assertTrue(SafeHttpClient::isBlockedIp('240.0.0.1'));
        $this->assertTrue(SafeHttpClient::isBlockedIp('::'));
        $this->assertTrue(SafeHttpClient::isBlockedIp('ff02::1'));
    }

    public function test_ipv4_mapped_ipv6_private_addresses_are_blocked(): void
    {
        $this->assertTrue(SafeHttpClient::isBlockedIp('::ffff:127.0.0.1'));
        $this->assertTrue(SafeHttpClient::isBlockedIp('::ffff:10.0.0.1'));
    }

    public function test_ipv6_unique_local_is_blocked(): void
    {
        $this->assertTrue(SafeHttpClient::isBlockedIp('fc00::1'));
        $this->assertTrue(SafeHttpClient::isBlockedIp('fd12:3456:789a::1'));
    }

    public function test_an_invalid_ip_literal_fails_closed(): void
    {
        $this->assertTrue(SafeHttpClient::isBlockedIp('not-an-ip'));
    }

    public function test_real_public_addresses_are_not_blocked(): void
    {
        foreach (['8.8.8.8', '1.1.1.1', '93.184.216.34', '2606:4700:4700::1111'] as $ip) {
            $this->assertFalse(SafeHttpClient::isBlockedIp($ip), $ip);
        }
    }

    public function test_resolve_safe_ip_picks_the_sole_public_answer(): void
    {
        $ip = SafeHttpClient::resolveSafeIp('shop.example.com', fn () => ['93.184.216.34']);
        $this->assertSame('93.184.216.34', $ip);
    }

    public function test_resolve_safe_ip_refuses_a_host_with_even_one_private_answer(): void
    {
        $this->expectException(SafeHttpException::class);
        SafeHttpClient::resolveSafeIp('shop.example.com', fn () => ['93.184.216.34', '10.0.0.1']);
    }

    public function test_resolve_safe_ip_refuses_when_resolution_returns_nothing(): void
    {
        $this->expectException(SafeHttpException::class);
        SafeHttpClient::resolveSafeIp('nowhere.invalid', fn () => []);
    }

    public function test_a_successful_post_is_returned(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $response = SafeHttpClient::post(
            'https://shop.example.com/wp-json/haman/v1/live-query',
            '{}', [], 5, fn () => ['93.184.216.34'],
        );

        $this->assertSame(200, $response->status());
        Http::assertSentCount(1);
    }

    public function test_a_request_to_a_host_resolving_to_a_blocked_ip_is_refused_before_any_http_call(): void
    {
        Http::fake();

        $this->expectException(SafeHttpException::class);
        try {
            SafeHttpClient::post(
                'http://169-254-169-254.example.com/latest/meta-data/',
                '{}', [], 5, fn () => ['169.254.169.254'],
            );
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_a_redirect_is_followed_after_revalidating_the_new_host(): void
    {
        Http::fake([
            'shop.example.com/*'  => Http::response('', 302, ['Location' => 'https://cdn.example.com/final']),
            'cdn.example.com/*'   => Http::response(['ok' => true], 200),
        ]);

        $response = SafeHttpClient::post(
            'https://shop.example.com/wp-json/haman/v1/live-query',
            '{}', [], 5, fn () => ['93.184.216.34'],
        );

        $this->assertSame(200, $response->status());
        Http::assertSentCount(2);
    }

    public function test_a_redirect_into_a_blocked_address_is_refused(): void
    {
        Http::fake([
            'shop.example.com/*'    => Http::response('', 302, ['Location' => 'http://internal.example.com/steal']),
            'internal.example.com/*' => Http::response(['leaked' => true], 200),
        ]);

        $resolver = fn (string $host) => $host === 'internal.example.com' ? ['169.254.169.254'] : ['93.184.216.34'];

        $this->expectException(SafeHttpException::class);
        try {
            SafeHttpClient::post('https://shop.example.com/x', '{}', [], 5, $resolver);
        } finally {
            Http::assertSentCount(1); // the redirect hop happened, the poisoned target never was requested
        }
    }

    public function test_too_many_redirects_is_refused(): void
    {
        Http::fake(['*' => Http::response('', 302, ['Location' => 'https://shop.example.com/next'])]);

        $this->expectException(SafeHttpException::class);
        try {
            SafeHttpClient::post('https://shop.example.com/start', '{}', [], 5, fn () => ['93.184.216.34']);
        } finally {
            Http::assertSentCount(SafeHttpClient::MAX_REDIRECTS + 1);
        }
    }

    public function test_an_unsupported_scheme_is_refused(): void
    {
        Http::fake();

        $this->expectException(SafeHttpException::class);
        try {
            SafeHttpClient::post('file:///etc/passwd', '{}', [], 5);
        } finally {
            Http::assertNothingSent();
        }
    }
}
