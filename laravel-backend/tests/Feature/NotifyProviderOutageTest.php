<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/**
 * Covers the other half of the all-providers-failed signal (the Python-side
 * write lives in llm_provider_service.py::record_all_providers_failed() and
 * isn't reachable from a PHP test): once that Redis key is set, a real
 * customer already got the fallback message instead of an answer, and this
 * command's only job is telling an admin, once per outage window.
 *
 * Uses a real, raw \Redis connection -- not the Redis:: facade, not a mock --
 * because that is exactly what the command itself uses and exactly what a
 * live incident proved the facade gets wrong here: database.redis.options.prefix
 * (Laravel's own stock default, derived from APP_NAME) silently prefixes every
 * facade command, so Redis::get() on the bare key Python actually writes reads
 * back null every time. A mock of the facade would have kept that bug green
 * forever; this test would have caught it on day one.
 */
class NotifyProviderOutageTest extends TestCase
{
    use RefreshDatabase;

    private const ALERT_KEY    = 'haman:alerts:all_providers_failed_at';
    private const COOLDOWN_KEY = 'haman:alerts:all_providers_failed_notified';

    private \Redis $redis;

    protected function setUp(): void {
        parent::setUp();
        $this->redis = new \Redis();
        $this->redis->connect(config('database.redis.default.host'), (int) config('database.redis.default.port'));
        $password = config('database.redis.default.password');
        if ($password) {
            $this->redis->auth($password);
        }
        $this->redis->select((int) config('database.redis.default.database', 0));
        $this->redis->del(self::ALERT_KEY, self::COOLDOWN_KEY);
    }

    protected function tearDown(): void {
        $this->redis->del(self::ALERT_KEY, self::COOLDOWN_KEY);
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

        $this->redis->set(self::ALERT_KEY, json_encode(['at' => now()->timestamp, 'reason' => 'all providers 429/empty']));

        $this->artisan('haman:notify-provider-outage')->assertSuccessful();

        $this->assertEquals(1, $admin1->fresh()->unreadNotifications()->count());
        $this->assertEquals(1, $admin2->fresh()->unreadNotifications()->count());
        $this->assertNotNull($this->redis->get(self::COOLDOWN_KEY));
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

        $this->redis->set(self::ALERT_KEY, json_encode(['at' => now()->timestamp, 'reason' => 'still failing']));
        $this->redis->set(self::COOLDOWN_KEY, '1');

        $this->artisan('haman:notify-provider-outage')->assertSuccessful();

        $this->assertEquals(0, $admin->fresh()->unreadNotifications()->count());
    }
}
