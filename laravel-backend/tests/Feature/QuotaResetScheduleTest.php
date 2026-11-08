<?php
namespace Tests\Feature;

use App\Models\{Plan, Tenant};
use App\Services\QuotaService;
use App\Support\{QuotaPeriod, Settings};
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Plan quota resets exactly once per period: on each tenant's own
 * anniversary (QuotaService::rolloverPeriodIfDue()).
 *
 * Until 2026-11 a calendar reset (haman:reset-usage, the 1st of every month)
 * ran as well. Every tenant whose anniversary is not the 1st was reset
 * twice a month — in November 2026 all nine active tenants (anniversary on
 * the 6th) were reset on the 1st and again on the 6th, an extra month of
 * quota each. These tests fail if a second reset path comes back.
 */
class QuotaResetScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_nothing_is_scheduled_to_reset_usage(): void
    {
        $this->app->make(Kernel::class)->bootstrap();
        $commands = collect($this->app->make(Schedule::class)->events())->map(fn ($e) => (string) $e->command)->implode("\n");

        $this->assertStringNotContainsString('reset-usage', $commands);
        $this->assertStringNotContainsStringIgnoringCase('ResetMonthlyUsage', $commands);
        $this->assertArrayNotHasKey('haman:reset-usage', Artisan::all(), 'the calendar reset command must not exist');
    }

    public function test_only_the_anniversary_rollover_writes_usage_back_to_zero(): void
    {
        // A static guard: any new code that zeroes the usage counters is a
        // second reset path, whatever it's called.
        $offenders = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') continue;
            $code = file_get_contents($file->getPathname());
            if (preg_match('/[\'"]usage_(tokens|messages)_current[\'"]\s*=>\s*0\b/', $code)
                || preg_match('/->usage_(tokens|messages)_current\s*=\s*0\b/', $code)) {
                $offenders[] = str_replace(app_path() . DIRECTORY_SEPARATOR, '', $file->getPathname());
            }
        }
        $this->assertSame(['Services' . DIRECTORY_SEPARATOR . 'QuotaService.php'], $offenders);
    }

    private function tenant(string $anchor, int $used): Tenant
    {
        $plan = Plan::create([
            'name' => 'P', 'slug' => 'p-' . Str::random(6), 'price_monthly' => 0,
            'max_chatbots' => 1, 'max_tokens_monthly' => 100000, 'is_active' => true, 'sort_order' => 0,
        ]);
        return Tenant::create([
            'slug' => 't-' . Str::random(6), 'name' => 'T', 'email' => Str::random(8) . '@example.test',
            'plan_id' => $plan->id, 'schema_name' => 'tenant_' . Str::random(8), 'status' => 'active',
            'quota_period_started_at' => $anchor, 'usage_tokens_current' => $used, 'usage_messages_current' => 7,
        ]);
    }

    public function test_the_first_of_the_month_does_not_reset_a_tenant_anchored_on_the_sixth(): void
    {
        $this->travelTo('2026-11-01 00:05:00');
        $tenant = $this->tenant('2026-10-06 15:42:51', 40000);

        Settings::set('quota.token_reservation_estimate', 100);
        $this->assertTrue(app(QuotaService::class)->checkAndReserveTokens($tenant)['allowed']);
        $tenant->refresh();
        $this->assertSame(40100, (int) $tenant->usage_tokens_current, 'November 1st is not this tenant\'s reset day');
        $this->assertSame('2026-11-06', QuotaPeriod::nextReset($tenant)->toDateString());
    }

    public function test_the_anniversary_resets_it_once(): void
    {
        $this->travelTo('2026-11-06 16:00:00');
        $tenant = $this->tenant('2026-10-06 15:42:51', 40000);

        Settings::set('quota.token_reservation_estimate', 100);
        $this->assertTrue(app(QuotaService::class)->checkAndReserveTokens($tenant)['allowed']);
        $tenant->refresh();
        $this->assertSame(100, (int) $tenant->usage_tokens_current, 'reset on the anniversary, then this request reserved');
        $this->assertSame('2026-11-06', $tenant->quota_period_started_at->toDateString());
        $this->assertSame('2026-12-06', QuotaPeriod::nextReset($tenant)->toDateString());
    }
}
