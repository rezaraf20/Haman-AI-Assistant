<?php
namespace Tests\Feature;

use Filament\Facades\Filament;
use Illuminate\Contracts\Http\Kernel;
use Tests\TestCase;

/**
 * The three bugs this test exists to catch (SetLocale seeing no user,
 * ShareSessionAcrossBrandDomains never widening the cookie on panel routes,
 * a Livewire redirect landing on /livewire/update) all had the same root
 * cause: each Filament panel builds its own middleware pipeline from
 * scratch, entirely separate from bootstrap/app.php's 'web' group, so a fix
 * made to one silently never reached the other two routes families.
 *
 * This does not re-litigate whether any one middleware belongs in the
 * pipeline — it only makes sure a difference between the 'web' group and a
 * panel's own array is a decision someone actually made, not a gap nobody
 * noticed. A middleware appearing in exactly one place has to be in
 * INTENTIONAL_DIFFERENCES, with a reason, or this fails.
 */
class MiddlewareParityTest extends TestCase
{
    /**
     * Middleware allowed to differ between the 'web' group and a panel's own
     * pipeline, and why. Keyed by the middleware's class name; the value is
     * shown in the failure message a change to this list produces, so it
     * doubles as the audit trail asked for when this test was written.
     *
     * @var array<string, string>
     */
    private const INTENTIONAL_DIFFERENCES = [
        'Filament\Http\Middleware\AuthenticateSession' => 'Filament-only: invalidates a session whose password hash no longer '
            . 'matches the user record. Ties into Filament\'s own login flow specifically; '
            . 'the plain web.php routes (landing, signup) have no equivalent concept.',
        'Filament\Http\Middleware\DisableBladeIconComponents' => 'Filament-only: turns off Blade icon components outside the '
            . 'panel, where Filament\'s own icon system is not in use.',
        'Filament\Http\Middleware\DispatchServingFilamentEvent' => 'Filament-only: fires the event panel plugins hook into. '
            . 'Meaningless for a request that never reaches a panel.',
        'Filament\Http\Middleware\Authenticate' => 'A panel-only concept, not a gap: the \'web\' group has no equivalent '
            . 'middleware at all, because bootstrap/app.php redirects an unauthenticated web.php visitor via '
            . 'redirectGuestsTo() at the kernel level instead of a middleware in the group array. Nothing is missing here; '
            . 'the two approaches just live in different places.',
        // Both sides of one alias, not two differences: ValidateCsrfToken is
        // `class ValidateCsrfToken extends VerifyCsrfToken {}`, a bare
        // rename with no behaviour of its own. The 'web' group carries the
        // Laravel 11+ name (bootstrap/app.php's web() helper installs it
        // under that name); the panels still list the pre-11 name. Same
        // class hierarchy, same check — each entry explains why the OTHER
        // one does not need a matching partner of its own name.
        'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken' => 'Same behaviour as the panels\' VerifyCsrfToken — see '
            . 'that entry.',
        'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken' => 'Same behaviour as the \'web\' group\'s ValidateCsrfToken '
            . '(ValidateCsrfToken extends VerifyCsrfToken with no changes) — the panels were built before Laravel 11 renamed '
            . 'it and were never updated to the new name, which is fine, since both names run the identical check.',
    ];

    /** The middleware Laravel's own 'web' group carries. */
    private function webGroupMiddleware(): array
    {
        $kernel = app(Kernel::class);
        $property = (new \ReflectionClass($kernel))->getProperty('middlewareGroups');
        $property->setAccessible(true);

        return $property->getValue($kernel)['web'];
    }

    /**
     * A panel's own middleware, both arrays combined and normalized: the
     * synthetic "panel:{id}" string stripped (it names no class, and exists
     * once per panel by construction — comparing it would only ever fail),
     * and de-duplicated, because SetLocale deliberately appears in both of a
     * panel's arrays (see AdminPanelProvider's own comment on why) and that
     * duplication is not itself a difference worth reporting here.
     */
    private function panelMiddleware(string $panelId): array
    {
        $panel = Filament::getPanel($panelId);

        return collect([...$panel->getMiddleware(), ...$panel->getAuthMiddleware()])
            ->reject(fn ($m) => str_starts_with($m, 'panel:'))
            ->unique()
            ->values()
            ->all();
    }

    private function assertParity(array $web, array $panel, string $panelLabel): void
    {
        $webOnly = array_diff($web, $panel);
        $panelOnly = array_diff($panel, $web);

        $unexplainedWebOnly = array_diff($webOnly, array_keys(self::INTENTIONAL_DIFFERENCES));
        $unexplainedPanelOnly = array_diff($panelOnly, array_keys(self::INTENTIONAL_DIFFERENCES));

        $this->assertEmpty(
            $unexplainedWebOnly,
            "The 'web' group has these middleware, {$panelLabel} does not, and neither is in "
                . "MiddlewareParityTest::INTENTIONAL_DIFFERENCES: " . implode(', ', $unexplainedWebOnly)
                . ". If this is deliberate, add it to the allowlist with a reason. If it is not, "
                . "add it to {$panelLabel}'s ->middleware()/->authMiddleware() array.",
        );

        $this->assertEmpty(
            $unexplainedPanelOnly,
            "{$panelLabel} has these middleware, the 'web' group does not, and neither is in "
                . "MiddlewareParityTest::INTENTIONAL_DIFFERENCES: " . implode(', ', $unexplainedPanelOnly)
                . ". If this is deliberate (a Filament-only concern), add it to the allowlist with a reason.",
        );
    }

    public function test_the_admin_panel_matches_the_web_group_except_for_documented_differences(): void
    {
        $this->assertParity($this->webGroupMiddleware(), $this->panelMiddleware('admin'), 'the admin panel');
    }

    public function test_the_customer_panel_matches_the_web_group_except_for_documented_differences(): void
    {
        $this->assertParity($this->webGroupMiddleware(), $this->panelMiddleware('customer'), 'the customer panel');
    }

    /**
     * The two panels should not drift from EACH OTHER either — nothing about
     * being the admin panel versus the customer panel explains a difference
     * in session, CSRF, or locale handling. Compared directly rather than
     * only transitively (each already having to match 'web'), because two
     * panels could each carry their own unexplained addition that happens to
     * not appear in the other and this catches that even if a future
     * INTENTIONAL_DIFFERENCES entry would let both individual comparisons
     * above pass.
     */
    public function test_the_two_panels_carry_the_same_middleware_as_each_other(): void
    {
        $admin = $this->panelMiddleware('admin');
        $customer = $this->panelMiddleware('customer');

        sort($admin);
        sort($customer);

        $this->assertSame($admin, $customer, 'the admin and customer panels have different middleware — see the diff above.');
    }

    /**
     * The specific bug this whole test file exists because of: proves
     * ShareSessionAcrossBrandDomains, missing from both panels until now,
     * is back on both, by name rather than only as part of the broader
     * parity check above (which would catch it too, but a test that says
     * exactly what it is protecting is worth having on its own).
     */
    public function test_both_panels_share_the_session_domain_middleware(): void
    {
        foreach (['admin', 'customer'] as $panelId) {
            $this->assertContains(
                \App\Http\Middleware\ShareSessionAcrossBrandDomains::class,
                $this->panelMiddleware($panelId),
                "the {$panelId} panel is missing ShareSessionAcrossBrandDomains — this is the 419 regression, back again",
            );
        }
    }
}
