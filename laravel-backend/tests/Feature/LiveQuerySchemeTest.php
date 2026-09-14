<?php
namespace Tests\Feature;

use App\Services\LiveQueryClient;
use Tests\TestCase;

/**
 * The http/https rule for live queries to a merchant's shop.
 *
 * A live query carries a shop's stock and prices and is signed with its
 * webhook secret, so it must never cross the public internet in the clear.
 * http is allowed only where the host provably cannot be a real shop — a
 * Docker service name, a private-use TLD, or a private IPv4 — which is what
 * makes an end-to-end test environment possible without a certificate.
 *
 * Must stay identical to product_tools.py's _scheme(): both sides call the
 * same endpoint, and a disagreement means one of them silently stops working.
 */
class LiveQuerySchemeTest extends TestCase
{
    public static function internalHosts(): array
    {
        return [
            'docker service name'  => ['testshop'],
            'another service name' => ['wordpress'],
            'dot-test tld'         => ['shop.test'],
            'dot-local tld'        => ['wp.local'],
            'dot-localhost'        => ['site.localhost'],
            'dot-internal'         => ['box.internal'],
            'loopback'             => ['127.0.0.1'],
            'ten-dot'              => ['10.1.2.3'],
            'one-nine-two'         => ['192.168.0.7'],
            'one-seven-two low'    => ['172.16.0.1'],
            'one-seven-two high'   => ['172.31.255.254'],
        ];
    }

    /** @dataProvider internalHosts */
    public function test_a_host_that_cannot_be_public_uses_http(string $host): void
    {
        $this->assertSame('http', LiveQueryClient::scheme($host));
    }

    public static function publicHosts(): array
    {
        return [
            'real customer'        => ['khonehrangi.ir'],
            'plain com'            => ['example.com'],
            'deep subdomain'       => ['shop.example.co.uk'],
            'just below 172.16'    => ['172.15.0.1'],
            'just above 172.31'    => ['172.32.0.1'],
            'eleven-dot'           => ['11.0.0.1'],
            'public ip'            => ['193.168.0.1'],
            'test as a label'      => ['mytest.com'],
            'test as the sld'      => ['test.com'],
            'local as a label'     => ['local.example.com'],
        ];
    }

    /**
     * @dataProvider publicHosts
     * The property that matters: a hijacked primary_domain must not become a
     * way to strip TLS off every live query for that tenant.
     */
    public function test_a_public_domain_can_never_be_downgraded(string $host): void
    {
        $this->assertSame('https', LiveQueryClient::scheme($host));
    }

    public function test_decorating_a_public_host_does_not_downgrade_it(): void
    {
        foreach (['evil.com:8080', 'evil.com/path', 'EVIL.COM', 'evil.com:80/x'] as $host) {
            $this->assertSame('https', LiveQueryClient::scheme($host), $host);
        }
    }

    public function test_the_two_languages_agree(): void
    {
        // The Python side is the other half of this rule; preflight compares
        // the two implementations directly. Here we just pin the handful of
        // cases most likely to drift.
        $python = base_path('../python-ai-service/app/services/tools/product_tools.py');
        if (!is_file($python)) {
            $this->markTestSkipped('Python tree not present in this image.');
        }

        $source = file_get_contents($python);
        foreach (['.test', '.local', '.localhost', '.internal', '.invalid'] as $suffix) {
            $this->assertStringContainsString($suffix, $source,
                "Python's _scheme() no longer treats {$suffix} as internal.");
        }
    }
}
