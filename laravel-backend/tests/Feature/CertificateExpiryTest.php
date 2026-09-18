<?php
namespace Tests\Feature;

use App\Support\CertificateExpiry;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The certificate warning has to fire while there is still time to act.
 *
 * Nothing here opens a socket: the network is not available in CI, and the
 * part worth testing is not OpenSSL's ability to read a date. It is which
 * hosts get watched, where the warning threshold sits, and whether an
 * unreachable host is treated as a problem rather than silently as fine --
 * that last one being how a monitor ends up reporting healthy forever.
 */
class CertificateExpiryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        config([
            'haman.domains.landing'    => 'hamanai.com',
            'haman.domains.app'        => 'app.hamanai.com',
            'haman.domains.api'        => 'api.hamanai.com',
            'haman.domains.api_public' => 'https://api.arshanweb.ir',
        ]);
    }

    public function test_it_watches_every_hostname_the_platform_answers_on(): void
    {
        $hosts = CertificateExpiry::hosts();

        foreach (['hamanai.com', 'app.hamanai.com', 'api.hamanai.com', 'api.arshanweb.ir'] as $host) {
            $this->assertContains($host, $hosts, "{$host} is not being watched");
        }
    }

    public function test_the_address_installed_on_customer_sites_is_watched_as_a_host(): void
    {
        // api_public is a URL in config, not a hostname. Watching it verbatim
        // would check a host called "https://api.arshanweb.ir" -- which fails
        // to resolve, every day, until someone stops reading the alerts.
        $this->assertContains('api.arshanweb.ir', CertificateExpiry::hosts());
        $this->assertNotContains('https://api.arshanweb.ir', CertificateExpiry::hosts());
    }

    public function test_a_host_listed_twice_is_checked_once(): void
    {
        config(['haman.domains.api_public' => 'https://api.hamanai.com']);

        $hosts = CertificateExpiry::hosts();

        $this->assertSame(count($hosts), count(array_unique($hosts)));
        $this->assertCount(3, $hosts);
    }

    public function test_an_empty_domain_is_not_watched(): void
    {
        config(['haman.domains.api' => '']);

        $this->assertNotContains('', CertificateExpiry::hosts());
    }

    public function test_a_host_that_cannot_be_reached_is_reported_not_ignored(): void
    {
        // The silent-failure case. A monitor that treats "could not connect"
        // as "nothing to report" is worse than no monitor, because it is
        // trusted.
        $result = CertificateExpiry::check('no-such-host.invalid');

        $this->assertFalse($result['ok']);
        $this->assertNull($result['days']);
        $this->assertNotNull($result['error']);
        $this->assertSame('no-such-host.invalid', $result['host']);
    }

    public function test_an_unreadable_host_counts_as_failing(): void
    {
        config([
            'haman.domains.landing'    => 'no-such-host.invalid',
            'haman.domains.app'        => 'no-such-host.invalid',
            'haman.domains.api'        => 'no-such-host.invalid',
            'haman.domains.api_public' => 'https://no-such-host.invalid',
        ]);

        $failing = CertificateExpiry::failing();

        $this->assertNotEmpty($failing, 'an unreachable host did not register as a problem');
    }

    public function test_the_threshold_leaves_room_to_act(): void
    {
        // Renewal starts around 30 days out. A threshold at or above that
        // would warn about every healthy certificate; far below it would warn
        // too late to fix by hand.
        $this->assertLessThan(30, CertificateExpiry::WARN_DAYS);
        $this->assertGreaterThanOrEqual(14, CertificateExpiry::WARN_DAYS);
    }
}
