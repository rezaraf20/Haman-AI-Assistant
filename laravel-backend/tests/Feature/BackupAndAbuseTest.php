<?php
namespace Tests\Feature;

use App\Models\{BackupRun, Plan, Tenant, User};
use App\Services\BackupService;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Artisan, DB, RateLimiter, Route};
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Backups, and the holes the abuse audit found.
 *
 * The backup tests deliberately do not shell out to pg_dump — the test
 * database is not the production one and the binary may not be present in a
 * CI image. What they do cover is everything around it that can silently go
 * wrong: retention keeping the wrong files, a failed run going unrecorded,
 * and the scratch-database guard that stops a restore ever touching
 * something that is not a scratch database.
 *
 * The real dump-and-restore is exercised against production by
 * hamman:backup-database followed by hamman:verify-backup, which is the only
 * test of a backup that means anything.
 */
class BackupAndAbuseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Settings::forget();
        RateLimiter::clear('login:ip:127.0.0.1');
    }

    // ── Backup bookkeeping ──────────────────────────────────────────────

    public function test_a_failed_backup_is_recorded_not_silently_dropped(): void
    {
        // A backup system that only writes a row when it works looks exactly
        // like one that has not run for a month.
        $run = BackupRun::create([
            'kind' => 'daily', 'status' => 'failed', 'error' => 'pg_dump exploded',
            'started_at' => now(), 'finished_at' => now(),
        ]);

        $this->assertEquals($run->id, BackupRun::latestAttempt()->id);
        $this->assertNull(BackupRun::latestSuccess(), 'A failure must not read as a success.');
    }

    public function test_the_panel_distinguishes_a_successful_backup_from_a_verified_one(): void
    {
        BackupRun::create([
            'kind' => 'daily', 'status' => 'success', 'filename' => 'a.dump', 'bytes' => 100,
            'started_at' => now()->subDay(), 'finished_at' => now()->subDay(),
        ]);

        $this->assertNotNull(BackupRun::latestSuccess());
        $this->assertNull(
            BackupRun::latestVerified(),
            'Taking a dump is not the same as proving it restores.'
        );
    }

    public function test_retention_keeps_seven_dailies_and_four_weeklies(): void
    {
        Settings::set('backup.keep_daily', 7);
        Settings::set('backup.keep_weekly', 4);

        // One backup a day for ten weeks.
        for ($daysAgo = 0; $daysAgo < 70; $daysAgo++) {
            $at = now()->subDays($daysAgo);
            BackupRun::create([
                'kind' => $at->isMonday() ? 'weekly' : 'daily',
                'status' => 'success',
                'filename' => 'b-' . $at->format('Y-m-d') . '.dump',
                'bytes' => 1000,
                'started_at' => $at,
                'finished_at' => $at,
            ]);
        }

        app(BackupService::class)->prune();

        $kept = BackupRun::succeeded()->orderByDesc('started_at')->get();

        $recent = $kept->filter(fn ($r) => $r->started_at->greaterThanOrEqualTo(now()->subDays(7)->startOfDay()));
        $this->assertGreaterThanOrEqual(7, $recent->count(), 'The daily window must be kept in full.');

        $older = $kept->filter(fn ($r) => $r->started_at->lessThan(now()->subDays(7)->startOfDay()));
        $this->assertLessThanOrEqual(4, $older->count(), 'Beyond the daily window, at most one per week.');

        // And one per DISTINCT week, not four from the same week.
        $weeks = $older->map(fn ($r) => $r->started_at->format('o-W'))->unique();
        $this->assertCount($older->count(), $weeks, 'The weekly copies must come from different weeks.');

        $this->assertGreaterThan(0, BackupRun::where('status', 'pruned')->count(), 'Something should have been pruned.');
    }

    public function test_retention_never_prunes_everything(): void
    {
        Settings::set('backup.keep_daily', 7);
        Settings::set('backup.keep_weekly', 4);

        BackupRun::create([
            'kind' => 'daily', 'status' => 'success', 'filename' => 'only.dump', 'bytes' => 10,
            'started_at' => now(), 'finished_at' => now(),
        ]);

        app(BackupService::class)->prune();

        $this->assertEquals(1, BackupRun::succeeded()->count());
    }

    public function test_the_remote_disk_refuses_to_build_without_credentials(): void
    {
        $this->expectException(\RuntimeException::class);

        app(BackupService::class)->remoteDisk();
    }

    public function test_offsite_is_only_reported_when_a_destination_is_actually_set(): void
    {
        $service = app(BackupService::class);
        $this->assertFalse($service->isRemoteConfigured(), 'local_only is not off-server.');

        Settings::set('backup.destination', 's3');
        $this->assertFalse($service->isRemoteConfigured(), 'A destination with no credentials is not configured.');

        foreach (['endpoint' => 'https://s3.example.test', 'bucket' => 'b', 'access_key' => 'k', 'secret_key' => 's'] as $k => $v) {
            Settings::set('backup.s3.' . $k, $v);
        }
        $this->assertTrue($service->isRemoteConfigured());
    }

    public function test_the_restore_command_refuses_any_database_that_is_not_a_scratch_name(): void
    {
        // The one mistake a restore tool must not be able to make.
        $command = new \App\Console\Commands\VerifyBackupCommand();
        $method = new \ReflectionMethod($command, 'assertScratchName');
        $method->setAccessible(true);

        foreach (['hamman', 'postgres', 'template1', 'verify_', 'verify_SHORT', '"; DROP DATABASE hamman; --'] as $name) {
            try {
                $method->invoke($command, $name);
                $this->fail("assertScratchName accepted [{$name}]");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Refusing', $e->getMessage());
            }
        }

        // And accepts exactly the shape it generates.
        $method->invoke($command, 'verify_abcdef123456');
        $this->assertTrue(true);
    }

    public function test_the_backup_command_reports_failure_with_a_non_zero_exit(): void
    {
        // No pg_dump in the test image, and that is the point: the command
        // has to surface the failure rather than claim success.
        $exit = Artisan::call('hamman:backup-database', ['--kind' => 'manual']);

        $run = BackupRun::latestAttempt();
        $this->assertNotNull($run);

        if ($run->status === 'failed') {
            $this->assertEquals(1, $exit);
            $this->assertNotEmpty($run->error);
        } else {
            $this->assertEquals(0, $exit);
            $this->assertNotNull($run->filename);
        }
    }

    // ── The abuse audit's findings ──────────────────────────────────────

    public function test_every_public_endpoint_that_costs_money_is_rate_limited(): void
    {
        $exit = Artisan::call('hamman:abuse-audit', ['--fail-on-gap' => true]);

        $this->assertEquals(0, $exit, "hamman:abuse-audit found a gap:\n" . Artisan::output());
    }

    public function test_login_and_register_are_throttled(): void
    {
        $throttles = [];
        foreach (Route::getRoutes() as $route) {
            $throttles[$route->uri()] = array_values(array_filter(
                $route->gatherMiddleware(),
                fn ($m) => is_string($m) && str_contains($m, 'throttle'),
            ));
        }

        $this->assertNotEmpty($throttles['api/v1/auth/login'] ?? [], 'Login was brute-forceable at full speed.');
        $this->assertNotEmpty($throttles['api/v1/auth/register'] ?? [], 'Register creates a tenant AND a schema.');
        $this->assertNotEmpty($throttles['payments/zarinpal/callback'] ?? []);
        $this->assertNotEmpty($throttles['api/v1/wp-plugin/latest-version'] ?? []);
    }

    public function test_the_zarinpal_callback_no_longer_accepts_every_verb(): void
    {
        foreach (Route::getRoutes() as $route) {
            if ($route->uri() !== 'payments/zarinpal/callback') continue;

            $methods = array_diff($route->methods(), ['HEAD']);
            $this->assertEqualsCanonicalizing(['GET', 'POST'], $methods,
                'Route::any exposed PUT/PATCH/DELETE on an endpoint that calls out to Zarinpal.');
            return;
        }

        $this->fail('The Zarinpal callback route is missing.');
    }

    public function test_the_plugin_api_group_is_throttled(): void
    {
        foreach (Route::getRoutes() as $route) {
            if ($route->uri() !== 'api/v1/sync/products') continue;

            $middleware = $route->gatherMiddleware();
            $this->assertTrue(
                (bool) array_filter($middleware, fn ($m) => is_string($m) && str_contains($m, 'plugin-api')),
                'Every sync call embeds text at the platform expense.',
            );
            return;
        }

        $this->fail('The sync route is missing.');
    }

    public function test_a_failed_login_eventually_locks_the_account(): void
    {
        Settings::set('limits.login_attempts_before_lockout', 3);
        Settings::set('limits.login_lockout_minutes', 15);
        Settings::set('limits.login_attempts_per_minute', 100);   // isolate the lockout

        $tenant = $this->tenant();
        $user = User::create([
            'email' => 'victim@example.test', 'password' => bcrypt('correct-horse'),
            'password_hash' => bcrypt('correct-horse'), 'name' => 'V', 'role' => 'owner',
            'tenant_id' => $tenant->id, 'email_verified_at' => now(),
        ]);

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong'])
                ->assertStatus(401);
        }

        $this->assertNotNull($user->fresh()->locked_until,
            'failed_login_count was being incremented while locked_until was never set — the lockout was dead code.');
        $this->assertTrue($user->fresh()->isLocked());

        // And the correct password does not get in while locked.
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'correct-horse'])
            ->assertStatus(401);
    }

    public function test_a_successful_login_clears_the_lockout_state(): void
    {
        Settings::set('limits.login_attempts_per_minute', 100);

        $tenant = $this->tenant();
        $user = User::create([
            'email' => 'ok@example.test', 'password' => bcrypt('right'),
            'password_hash' => bcrypt('right'), 'name' => 'O', 'role' => 'owner',
            'tenant_id' => $tenant->id, 'email_verified_at' => now(),
            'failed_login_count' => 2,
        ]);

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'right'])
            ->assertOk();

        $this->assertEquals(0, $user->fresh()->failed_login_count);
        $this->assertNull($user->fresh()->locked_until);
    }

    public function test_login_is_rate_limited_by_ip_and_by_address(): void
    {
        Settings::set('limits.login_attempts_per_minute', 3);
        Settings::set('limits.login_attempts_before_lockout', 0);   // isolate the throttle

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.test', 'password' => 'x'])
                ->assertStatus(401);
        }

        $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.test', 'password' => 'x'])
            ->assertStatus(429);
    }

    public function test_sync_payloads_are_capped_on_every_endpoint(): void
    {
        Settings::set('limits.sync_items_per_request', 2);

        $source = file_get_contents(app_path('Http/Controllers/Api/V1/SyncController.php'));

        // pages and faqs were unbounded: one request could hand over an
        // arbitrarily long array and every item got embedded.
        $this->assertSame(3, substr_count($source, '$this->itemCap()'),
            'All three sync endpoints must share one cap.');
        $this->assertStringNotContainsString("'required|array|min:1',", $source,
            'An uncapped array rule survived.');
    }

    public function test_the_portal_otp_send_is_capped_per_ip(): void
    {
        // SmsService only enforced a cooldown per phone NUMBER, so cycling
        // numbers sent unlimited SMS on the platform account.
        $source = file_get_contents(app_path('Livewire/OtpLogin.php'));

        $this->assertStringContainsString('withinIpBudget', $source);
        $this->assertStringContainsString('portal_otp_per_ip_per_day', $source);
        $this->assertSame(2, substr_count($source, '$this->withinIpBudget()'),
            'Both sendCode and resendCode must be capped — a resend costs the same message.');
    }

    public function test_the_portal_email_login_is_rate_limited(): void
    {
        // A Livewire action arrives on /livewire/update, so the route
        // throttle never covered it.
        $source = file_get_contents(app_path('Livewire/EmailLogin.php'));

        $this->assertStringContainsString('RateLimiter::tooManyAttempts', $source);
        $this->assertStringContainsString('locked_until', $source);
    }

    private function tenant(): Tenant
    {
        $plan = Plan::create([
            'name' => 'P', 'slug' => 'p-' . Str::random(6), 'price_monthly' => 0,
            'max_chatbots' => 1, 'max_tokens_monthly' => 1000, 'is_active' => true, 'sort_order' => 0,
        ]);

        return Tenant::create([
            'slug' => 't-' . Str::random(6), 'name' => 'C',
            'email' => Str::random(8) . '@example.test', 'plan_id' => $plan->id,
            'schema_name' => 'public', 'status' => 'active', 'trial_ends_at' => now()->addDay(),
        ]);
    }
}
