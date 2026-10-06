<?php
namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Tenant;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use RuntimeException;

/**
 * Acceptance criterion 7: two simultaneous requests against the very last
 * remaining token must not both be let through.
 * QuotaService::checkAndReserveTokens() closes this with a row-locked
 * transaction (Tenant::lockForUpdate()) — this test proves that lock
 * actually serializes two REAL, simultaneous OS processes, not just two
 * sequential calls within one PHP process. Two sequential calls in one
 * process would pass even WITHOUT the lock (nothing in a single-threaded
 * sequential call can race with itself), so that would prove nothing about
 * the race this exists to close — only a genuine second process, with its
 * own PHP runtime and its own database connection, can.
 *
 * Deliberately does NOT use RefreshDatabase: the two child processes
 * (tests/Support/quota_race_worker.php) need to see the tenant row this
 * test creates through their OWN connections. RefreshDatabase wraps each
 * test in an uncommitted transaction on the test's own connection, which a
 * separate process could never see — so this test commits for real and
 * cleans up explicitly in tearDown() instead.
 */
class TokenQuotaRaceConditionTest extends TestCase
{
    private ?string $tenantId = null;
    private ?string $planId = null;

    protected function tearDown(): void
    {
        if ($this->tenantId) {
            DB::table('tenants')->where('id', $this->tenantId)->delete();
        }
        if ($this->planId) {
            DB::table('plans')->where('id', $this->planId)->delete();
        }
        parent::tearDown();
    }

    public function test_two_simultaneous_requests_against_the_last_token_only_let_one_through(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is not available in this PHP build.');
        }

        $estimate = max(1, (int) Settings::get('quota.token_reservation_estimate'));

        $plan = Plan::create([
            'name' => 'Race Test Plan', 'slug' => 'race-' . Str::random(8),
            'price_monthly' => 0, 'max_chatbots' => 1,
            // Room for exactly ONE reservation — a second one must not fit.
            'max_tokens_monthly' => $estimate,
            'max_messages_monthly' => 1000,
            'quota_exceeded_behavior' => 'stop',
            'is_active' => true, 'sort_order' => 0,
        ]);
        $this->planId = $plan->id;

        $tenant = Tenant::create([
            'slug' => 'race-' . Str::random(8), 'name' => 'Race Tenant',
            'email' => Str::random(12) . '@race.example.com', 'plan_id' => $plan->id,
            'schema_name' => 'placeholder', 'status' => 'active', 'trial_ends_at' => now()->addDays(14),
            'settings' => ['webhook_secret' => Str::random(32)],
            'quota_period_started_at' => now(),
            'usage_tokens_current' => 0, 'usage_messages_current' => 0,
        ]);
        $this->tenantId = $tenant->id;

        $script = base_path('tests/Support/quota_race_worker.php');
        $tmp = sys_get_temp_dir();
        $readyA = $tmp . '/race_ready_a_' . Str::random(8);
        $readyB = $tmp . '/race_ready_b_' . Str::random(8);
        $goFile = $tmp . '/race_go_' . Str::random(8);
        $resultA = $tmp . '/race_result_a_' . Str::random(8);
        $resultB = $tmp . '/race_result_b_' . Str::random(8);

        $spawn = fn (string $readyFile, string $resultFile) => proc_open(
            ['php', $script, $tenant->id, $readyFile, $goFile, $resultFile],
            [], $pipes,
        );

        $procA = $spawn($readyA, $resultA);
        $procB = $spawn($readyB, $resultB);

        if (!is_resource($procA) || !is_resource($procB)) {
            $this->fail('Could not spawn worker processes for the race test.');
        }

        // Wait until BOTH workers are ready and blocked on the go file,
        // then release both at once — this is what makes the overlap real
        // rather than lucky timing.
        $deadline = microtime(true) + 10;
        while (!file_exists($readyA) || !file_exists($readyB)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Worker processes never signalled ready.');
            }
            usleep(500);
        }
        file_put_contents($goFile, '1');

        proc_close($procA);
        proc_close($procB);

        $outcomeA = json_decode(@file_get_contents($resultA) ?: 'null', true);
        $outcomeB = json_decode(@file_get_contents($resultB) ?: 'null', true);
        foreach ([$readyA, $readyB, $goFile, $resultA, $resultB] as $f) {
            @unlink($f);
        }

        $this->assertIsArray($outcomeA, 'Worker A produced no result: ' . json_encode($outcomeA));
        $this->assertIsArray($outcomeB, 'Worker B produced no result: ' . json_encode($outcomeB));

        $allowedCount = (int) ($outcomeA['allowed'] ?? false) + (int) ($outcomeB['allowed'] ?? false);

        $this->assertSame(1, $allowedCount,
            'Exactly one of the two simultaneous requests must be let through on the last token. Got: '
            . json_encode(['A' => $outcomeA, 'B' => $outcomeB]));

        $final = DB::table('tenants')->where('id', $tenant->id)->first(['usage_tokens_current']);
        $this->assertSame($estimate, (int) $final->usage_tokens_current,
            'Only one reservation worth of usage should have been recorded, not two, and not zero.');
    }
}
