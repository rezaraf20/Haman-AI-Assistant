<?php
namespace Tests\Feature;

use App\Support\BrandDomains;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One application, four hostnames.
 *
 *   hamanai.com       the public site and signup
 *   app.hamanai.com   the customer and admin panels
 *   api.hamanai.com   the API and the widget
 *   api.arshanweb.ir  the same API, permanently
 *
 * Two properties hold the arrangement together, and both fail quietly when
 * broken -- which is why they are tested rather than trusted.
 *
 * The first is the session cookie. Signing up happens on one host and ends on
 * another, so the cookie has to be widened to .hamanai.com to travel. It must
 * NOT be widened on api.arshanweb.ir, a different registrable domain whose
 * browser would reject the cookie and break every session-backed page there
 * with a 419 and no log entry.
 *
 * The second is which host the links and the canonical name. The landing page
 * renders on all four, so left alone it would offer search engines four
 * copies of one site, and a signup would hand its visitor a panel link on
 * whichever hostname they happened to arrive through.
 */
class BrandDomainsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            // StartSession attaches no cookie at all under the 'array' driver
            // that CI runs with, which would make every cookie assertion here
            // pass by finding nothing. A real driver is the point of the test.
            'session.driver'           => 'file',

            'haman.domains.landing'    => 'hamanai.com',
            'haman.domains.app'        => 'app.hamanai.com',
            'haman.domains.api'        => 'api.hamanai.com',
            'haman.domains.api_public' => 'https://api.arshanweb.ir',
            'haman.domains.session'    => '.hamanai.com',
        ]);
    }

    /** The cookie the browser is asked to keep, for a request to $host. */
    private function sessionCookieDomain(string $host): ?string
    {
        $response = $this->get("https://{$host}/");

        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === config('session.cookie')) {
                return $cookie->getDomain();
            }
        }

        return null;
    }

    public function test_the_hamanai_hosts_share_one_session_cookie(): void
    {
        foreach (['hamanai.com', 'www.hamanai.com', 'app.hamanai.com', 'api.hamanai.com'] as $host) {
            $this->assertSame(
                '.hamanai.com',
                $this->sessionCookieDomain($host),
                "{$host} did not get the shared cookie, so signup cannot reach the panel logged in",
            );
        }
    }

    public function test_the_permanent_api_host_keeps_its_own_cookie(): void
    {
        // The whole point of the middleware. A shared Domain=.hamanai.com here
        // is rejected by the browser outright: no session, and every CSRF
        // check on the host turns into a 419.
        $this->assertNotSame(
            '.hamanai.com',
            $this->sessionCookieDomain('api.arshanweb.ir'),
            'api.arshanweb.ir was given a cookie scoped to a domain it is not part of',
        );
    }

    public function test_a_lookalike_domain_does_not_get_our_cookie(): void
    {
        // "evilhamanai.com" ends with "hamanai.com". Matching on the bare
        // suffix instead of ".hamanai.com" would hand it the session cookie.
        $this->assertNotSame(
            '.hamanai.com',
            $this->sessionCookieDomain('evilhamanai.com'),
            'a domain that merely ends in hamanai.com was treated as ours',
        );
    }

    public function test_panel_links_point_at_the_panel_host_from_the_landing_page(): void
    {
        $this->get('https://hamanai.com/')
            ->assertStatus(200)
            ->assertSee('https://app.hamanai.com/portal/login', escape: false);
    }

    public function test_the_permanent_api_host_keeps_its_links_to_itself(): void
    {
        // Relative, so a visitor on this host is never sent to a domain whose
        // session cookie their browser will not carry.
        $html = $this->get('https://api.arshanweb.ir/')->getContent();

        $this->assertStringNotContainsString('https://app.hamanai.com/portal', $html);
        $this->assertStringContainsString('/portal/login', $html);
    }

    public function test_the_canonical_link_always_names_the_landing_host(): void
    {
        foreach (['hamanai.com', 'app.hamanai.com', 'api.hamanai.com', 'api.arshanweb.ir'] as $host) {
            $this->get("https://{$host}/")
                ->assertSee('<link rel="canonical" href="https://hamanai.com/">', escape: false);
        }
    }

    public function test_only_the_landing_host_invites_indexing(): void
    {
        $landing = $this->get('https://hamanai.com/robots.txt');

        $landing->assertStatus(200);
        $landing->assertSee('Sitemap: https://hamanai.com/sitemap.xml');
        // The selective rules, not a blanket refusal.
        $landing->assertSee('Disallow: /admin');
        $landing->assertSee('Allow: /$');

        foreach (['app.hamanai.com', 'api.hamanai.com', 'api.arshanweb.ir'] as $host) {
            $body = $this->get("https://{$host}/robots.txt")->getContent();

            $this->assertStringContainsString('Disallow: /', $body, "{$host} invited indexing");
            // A blanket refusal and nothing else: no sitemap offered, and none
            // of the per-path rules, which would mean the landing branch ran.
            $this->assertStringNotContainsString('Sitemap:', $body, "{$host} offered a sitemap");
            $this->assertStringNotContainsString('Allow: /$', $body, "{$host} served the landing rules");
        }
    }

    public function test_the_sitemap_lists_the_landing_host_whoever_serves_it(): void
    {
        $this->get('https://app.hamanai.com/sitemap.xml')
            ->assertStatus(200)
            ->assertSee('<loc>https://hamanai.com/</loc>', escape: false);
    }

    public function test_the_api_address_given_to_customers_does_not_follow_the_panel_host(): void
    {
        // A customer who opened the panel on app.hamanai.com must still be
        // handed api.arshanweb.ir to paste into their own site. This is the
        // one value that moves on its own schedule, years from now.
        $this->assertSame(
            'https://api.arshanweb.ir/api/v1',
            BrandDomains::publicApiUrl('/api/v1'),
        );
    }
}
