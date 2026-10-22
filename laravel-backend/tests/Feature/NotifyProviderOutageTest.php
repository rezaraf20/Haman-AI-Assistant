<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Covers the other half of the all-providers-failed signal (the Python-side
 * write lives in llm_provider_service.py::record_all_providers_failed() and
 * isn't reachable from a PHP test): once that Redis key is set, a real
 * customer already got the fallback message instead of an answer, and this
 * command's only job is telling an admin, once per outage window.
 *
 * Uses Redis::connection('shared') -- the exact same connection the command
 * itself uses, registered in AppServiceProvider -- not the bare facade and
 * not a mock. A live incident proved the bare facade's default connection
 * silently prefixes every key, so a mock of it (or testing against it
 * directly) would have kept that exact bug green forever: the mock can't
 * know about a prefix it was never told to model.
 */
class NotifyProviderOutageTest extends TestCase
{
    use RefreshDatabase;

    private const ALERT_KEY    = 'shared:haman:alerts:all_providers_failed_at';
    private const COOLDOWN_KEY = 'haman:alerts:all_providers_failed_notified';

    protected function setUp(): void {
        parent::setUp();
        Redis::connection('shared')->del(self::ALERT_KEY, self::COOLDOWN_KEY);
    }

    protected function tearDown(): void {
        Redis::connection('shared')->del(self::ALERT_KEY, self::COOLDOWN_KEY);
        parent::tearDown();
    }

    private function makeAdmin(): User {
        $admin = User::create([
            'email' => Str::random(10) . '@example.test',
            'password' => bcrypt('irrelevant'),
            'password_hash' => bcrypt('irrelevant'),
            'name' => 'Platform Admin',
            'role' => 'owner',
            'email_verified_at' => now(),
        ]);
        $admin->is_platform_admin = true;
        $admin->save();
        return $admin;
    }

    public function test_an_outage_signal_notifies_every_admin_once(): void
    {
        $admin1 = $this->makeAdmin();
        $admin2 = $this->makeAdmin();

        Redis::connection('shared')->set(self::ALERT_KEY, json_encode(['at' => now()->timestamp, 'reason' => 'all providers 429/empty']));

        $this->artisan('haman:notify-provider-outage')->assertSuccessful();

        $this->assertEquals(1, $admin1->fresh()->unreadNotifications()->count());
        $this->assertEquals(1, $admin2->fresh()->unreadNotifications()->count());
        $this->assertNotNull(Redis::connection('shared')->get(self::COOLDOWN_KEY));
    }

    public function test_no_signal_notifies_no_one(): void
    {
        $admin = $this->makeAdmin();

        $this->artisan('haman:notify-provider-outage')->assertSuccessful();

        $this->assertEquals(0, $admin->fresh()->unreadNotifications()->count());
    }

    public function test_within_cooldown_does_not_renotify(): void
    {
        $admin = $this->makeAdmin();

        Redis::connection('shared')->set(self::ALERT_KEY, json_encode(['at' => now()->timestamp, 'reason' => 'still failing']));
        Redis::connection('shared')->set(self::COOLDOWN_KEY, '1');

        $this->artisan('haman:notify-provider-outage')->assertSuccessful();

        $this->assertEquals(0, $admin->fresh()->unreadNotifications()->count());
    }
}
