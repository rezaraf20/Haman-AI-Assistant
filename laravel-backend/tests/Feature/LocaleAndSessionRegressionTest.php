<?php
namespace Tests\Feature;

use App\Models\{Plan, Tenant, User};
use App\Services\TenantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression coverage for the three panel bugs found in the same session:
 * SetLocale reading no user, a Livewire redirect landing on the AJAX
 * transport URL instead of the page, and the session cookie's Domain
 * attribute disagreeing between a panel route and a plain web.php one on
 * the same host. See MiddlewareParityTest for the structural cause common
 * to all three (each Filament panel building its own middleware pipeline,
 * disconnected from bootstrap/app.php's 'web' group).
 *
 * Every request here goes through the real, routed HTTP kernel — not
 * Livewire::test() alone, which never touches the middleware stack a page
 * load does, and would have passed throughout the whole time these bugs
 * were live.
 */
class LocaleAndSessionRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function portalUser(string $locale = 'en'): User
    {
        $plan = Plan::create([
            'name' => 'Test Plan', 'slug' => 'test-' . Str::random(8),
            'price_monthly' => 0, 'max_chatbots' => 1, 'max_tokens_monthly' => 1000000,
            'is_active' => true, 'sort_order' => 0,
        ]);

        $tenant = Tenant::create([
            'slug' => 'test-' . Str::random(8), 'name' => 'Test Tenant',
            'email' => Str::random(12) . '@example.test', 'plan_id' => $plan->id,
            'schema_name' => 'placeholder', 'status' => 'active', 'trial_ends_at' => now()->addDays(14),
        ]);
        $schema = 'tenant_' . str_replace('-', '', $tenant->id);
        $tenant->update(['schema_name' => $schema]);
        app(TenantService::class)->createSchema($schema);

        return User::create([
            'tenant_id' => $tenant->id,
            'email' => Str::random(10) . '@example.test',
            'password' => bcrypt('x'), 'password_hash' => bcrypt('x'),
            'name' => 'Portal User', 'role' => 'owner', 'locale' => $locale,
            'email_verified_at' => now(),
        ]);
    }

    private function admin(string $locale = 'en'): User
    {
        $user = User::create([
            'email' => Str::random(10) . '@example.test',
            'password' => bcrypt('x'), 'password_hash' => bcrypt('x'),
            'name' => 'Admin', 'role' => 'owner', 'locale' => $locale,
            'email_verified_at' => now(),
        ]);
        $user->platform_role = 'admin';
        $user->platform_is_active = true;
        $user->save();

        return $user;
    }

    // ── The language bug: a real logged-in request must see the user ─────

    public function test_a_logged_in_portal_user_with_locale_fa_gets_an_rtl_page(): void
    {
        $user = $this->portalUser(locale: 'fa');
        $this->actingAs($user, 'web');

        $html = $this->get('https://app.hamanai.com/portal')->getContent();

        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString('lang="fa"', $html);
    }

    public function test_a_logged_in_admin_with_locale_fa_gets_an_rtl_page(): void
    {
        $admin = $this->admin(locale: 'fa');
        $this->actingAs($admin, 'web');

        $html = $this->get('https://app.hamanai.com/admin')->getContent();

        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString('lang="fa"', $html);
    }

    /**
     * The exact reported symptom: log in (English account), change the
     * language to fa from the profile page, and the NEXT real page load —
     * not the Livewire component still in memory, an actual second
     * request — has to render in Persian. This is what a real logged-in
     * request through the full middleware pipeline looks like; the earlier,
     * false-positive version of this class of test used Livewire::test()
     * alone and would have stayed green through the whole outage.
     */
    public function test_switching_locale_to_fa_from_the_profile_page_is_rtl_on_the_next_load(): void
    {
        $user = $this->portalUser(locale: 'en');
        $this->actingAs($user, 'web');

        $this->assertStringContainsString('lang="en"', $this->get('https://app.hamanai.com/portal')->getContent());

        Livewire::test(\App\Filament\Customer\Pages\Profile::class)
            ->set('data.locale', 'fa')
            ->set('data.name', $user->name)
            ->set('data.email', $user->email)
            ->call('save');

        $html = $this->get('https://app.hamanai.com/portal')->getContent();
        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString('lang="fa"', $html);
    }

    // ── The 405 bug: saving a profile must never redirect to the AJAX URL ─

    public function test_saving_the_customer_profile_with_a_locale_change_never_redirects_to_livewire_update(): void
    {
        $user = $this->portalUser(locale: 'en');
        $this->actingAs($user, 'web');

        Livewire::test(\App\Filament\Customer\Pages\Profile::class)
            ->set('data.locale', 'fa')
            ->set('data.name', $user->name)
            ->set('data.email', $user->email)
            ->call('save')
            ->assertRedirect('https://app.hamanai.com/portal/profile');
    }

    public function test_saving_the_admin_profile_with_a_locale_change_never_redirects_to_livewire_update(): void
    {
        $admin = $this->admin(locale: 'en');
        $this->actingAs($admin, 'web');

        Livewire::test(\App\Filament\Pages\Profile::class)
            ->set('data.locale', 'fa')
            ->set('data.name', $admin->name)
            ->set('data.email', $admin->email)
            ->call('save')
            ->assertRedirect('https://app.hamanai.com/admin/profile');
    }

    // ── The 419 bug: one Domain attribute for one host, regardless of

    /** Which Set-Cookie Domain a URL's response carries for the session cookie, or null (host-only/absent). */
    private function sessionCookieDomain(string $url): ?string
    {
        $response = $this->get($url);

        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === config('session.cookie')) {
                return $cookie->getDomain();
            }
        }

        return null;
    }

    public function test_a_panel_route_and_a_web_route_agree_on_the_session_cookies_domain(): void
    {
        $panel = $this->sessionCookieDomain('https://app.hamanai.com/admin/login');
        $web = $this->sessionCookieDomain('https://app.hamanai.com/livewire/update');

        $this->assertSame(
            '.hamanai.com',
            $panel,
            'the admin panel did not widen the session cookie — this is the 419 regression, back again',
        );
        $this->assertSame($web, $panel, 'a panel route and a web route disagree on the session cookie\'s Domain for the same host');
    }

    public function test_the_permanent_api_host_still_gets_a_host_only_cookie_from_a_panel_route(): void
    {
        $this->assertNotSame(
            '.hamanai.com',
            $this->sessionCookieDomain('https://api.arshanweb.ir/admin/login'),
            'api.arshanweb.ir was handed a cookie scoped to a domain it is not part of',
        );
    }

    /**
     * The code fix stops a NEW host-only cookie from being issued; it does
     * nothing on its own about a browser that already captured one before
     * the fix. Simulates exactly that: a request carrying both a stale,
     * host-only cookie AND (implicitly, once the fix lands) the correct
     * wide one, and checks the response actively tells the browser to
     * delete the stale one rather than leaving it to linger indefinitely.
     */
    public function test_a_stale_host_only_cookie_is_actively_expired_on_a_shared_host(): void
    {
        $response = $this->get('https://app.hamanai.com/admin/login');

        $expiring = collect($response->headers->getCookies())
            ->first(fn ($c) => $c->getName() === config('session.cookie') && $c->getDomain() === null);

        $this->assertNotNull($expiring, 'no host-only expiry cookie was sent alongside the widened one');
        $this->assertLessThan(now()->timestamp, $expiring->getExpiresTime(), 'the cleanup cookie is not actually expired');

        // And the real, correctly-scoped cookie is still there, undisturbed.
        $real = collect($response->headers->getCookies())
            ->first(fn ($c) => $c->getName() === config('session.cookie') && $c->getDomain() === '.hamanai.com');
        $this->assertNotNull($real, 'the expiry cookie replaced the real one instead of sitting alongside it');
    }

    public function test_the_permanent_api_host_gets_no_expiry_cookie_since_it_never_had_the_bug(): void
    {
        $response = $this->get('https://api.arshanweb.ir/admin/login');

        $hostOnlyCount = collect($response->headers->getCookies())
            ->filter(fn ($c) => $c->getName() === config('session.cookie'))
            ->count();

        // Exactly one Set-Cookie for this name: the ordinary session cookie.
        // A second, expiring one here would be pointless noise on a host
        // that never issued the wide-scoped cookie in the first place.
        $this->assertSame(1, $hostOnlyCount);
    }
}
