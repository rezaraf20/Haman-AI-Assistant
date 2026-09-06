<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\{User, LlmProviderProfile};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/**
 * Covers the other half of the LLM provider auto-disable rule (the
 * Python-side threshold logic lives in llm_provider_service.py and isn't
 * reachable from a PHP test): once a provider is flagged disabled_reason
 * set / disabled_notified_at null, this command must alert every platform
 * admin exactly once, matching the real incident this was built for — a
 * dead xAI profile sat at 61 straight failures with nobody told.
 */
class NotifyDisabledProvidersTest extends TestCase
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

    public function test_disabled_provider_notifies_every_platform_admin_exactly_once(): void
    {
        $admin1 = $this->makeAdmin();
        $admin2 = $this->makeAdmin();

        $profile = LlmProviderProfile::create([
            'name' => 'xAI (grok-2-latest)', 'provider' => 'xai', 'model_name' => 'grok-2-latest',
            'priority' => 2, 'is_active' => false, 'consecutive_failures' => 61,
            'disabled_reason' => 'Auto-disabled after 5 consecutive failures.',
        ]);

        $this->artisan('hamman:notify-disabled-providers')->assertSuccessful();

        $this->assertEquals(1, $admin1->fresh()->unreadNotifications()->count());
        $this->assertEquals(1, $admin2->fresh()->unreadNotifications()->count());
        $this->assertNotNull($profile->fresh()->disabled_notified_at);

        // A second run must not re-notify — disabled_notified_at is now set.
        $this->artisan('hamman:notify-disabled-providers')->assertSuccessful();
        $this->assertEquals(1, $admin1->fresh()->unreadNotifications()->count());
    }

    public function test_healthy_provider_never_notifies_anyone(): void
    {
        $admin = $this->makeAdmin();
        LlmProviderProfile::create([
            'name' => 'Groq', 'provider' => 'groq', 'model_name' => 'openai/gpt-oss-20b',
            'priority' => 1, 'is_active' => true, 'consecutive_failures' => 0,
        ]);

        $this->artisan('hamman:notify-disabled-providers')->assertSuccessful();

        $this->assertEquals(0, $admin->fresh()->unreadNotifications()->count());
    }
}
