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
 */
class NotifyProviderOutageTest extends TestCase
{
    use RefreshDatabase;

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

        Redis::shouldReceive('get')
            ->with('haman:alerts:all_providers_failed_at')
            ->andReturn(json_encode(['at' => now()->timestamp, 'reason' => 'all providers 429/empty']));
        Redis::shouldReceive('get')
            ->with('haman:alerts:all_providers_failed_notified')
            ->andReturn(null);
        Redis::shouldReceive('set')
            ->with('haman:alerts:all_providers_failed_notified', '1', 'EX', 1800)
            ->once();

        $this->artisan('haman:notify-provider-outage')->assertSuccessful();

        $this->assertEquals(1, $admin1->fresh()->unreadNotifications()->count());
        $this->assertEquals(1, $admin2->fresh()->unreadNotifications()->count());
    }

    public function test_no_signal_notifies_no_one(): void
    {
        $admin = $this->makeAdmin();

        Redis::shouldReceive('get')
            ->with('haman:alerts:all_providers_failed_at')
            ->andReturn(null);

        $this->artisan('haman:notify-provider-outage')->assertSuccessful();

        $this->assertEquals(0, $admin->fresh()->unreadNotifications()->count());
    }

    public function test_within_cooldown_does_not_renotify(): void
    {
        $admin = $this->makeAdmin();

        Redis::shouldReceive('get')
            ->with('haman:alerts:all_providers_failed_at')
            ->andReturn(json_encode(['at' => now()->timestamp, 'reason' => 'still failing']));
        Redis::shouldReceive('get')
            ->with('haman:alerts:all_providers_failed_notified')
            ->andReturn('1');
        Redis::shouldReceive('set')->never();

        $this->artisan('haman:notify-provider-outage')->assertSuccessful();

        $this->assertEquals(0, $admin->fresh()->unreadNotifications()->count());
    }
}
