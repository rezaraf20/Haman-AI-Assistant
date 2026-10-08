<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\{ApiKey, ChatbotIndexEntry, Plan, Tenant, User};
use App\Services\TenantService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * khonehrangi.ir never logged into the portal, so the (login-gated)
 * onboarding checklist could never have reached that merchant — this is
 * the active counterpart: a scheduled job that notices on its own and
 * reaches out, instead of waiting to be looked at. SMS, not email, by
 * explicit instruction: email deliverability was never confirmed to reach
 * a real inbox (see signup.require_email_verification's docblock), while
 * SMS already demonstrably works.
 */
class NotifyUnconnectedChatbotsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(): array
    {
        $plan = Plan::create([
            'name' => 'Test Plan', 'slug' => 'test-' . Str::random(8),
            'price_monthly' => 0, 'max_chatbots' => 1, 'max_tokens_monthly' => 1000000,
            'is_active' => true, 'sort_order' => 0,
        ]);
        $tenant = Tenant::create([
            'slug' => 'test-' . Str::random(8), 'name' => 'Shop',
            'email' => Str::random(12) . '@example.test', 'phone' => '09120000000',
            'plan_id' => $plan->id, 'schema_name' => 'placeholder', 'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
        ]);
        $schema = 'tenant_' . str_replace('-', '', $tenant->id);
        $tenant->update(['schema_name' => $schema]);
        app(TenantService::class)->createSchema($schema);

        return compact('tenant', 'schema');
    }

    private function makeUnconnectedChatbot(array $tenantData, \Carbon\Carbon $createdAt): string
    {
        $chatbotId = (string) Str::uuid();
        DB::table('chatbot_index')->insert([
            'chatbot_id' => $chatbotId, 'tenant_id' => $tenantData['tenant']->id,
            'schema_name' => $tenantData['schema'], 'is_active' => true, 'name' => 'Bot',
            'created_at' => $createdAt,
        ]);

        return $chatbotId;
    }

    public function test_a_chatbot_past_the_first_threshold_gets_a_first_alert(): void
    {
        $tenantData = $this->makeTenant();
        $chatbotId = $this->makeUnconnectedChatbot($tenantData, now()->subHours(50)); // default threshold: 48h

        $this->artisan('haman:notify-unconnected-chatbots')->run();

        $entry = ChatbotIndexEntry::find($chatbotId);
        $this->assertNotNull($entry->connection_alert_first_sent_at);
        $this->assertNull($entry->connection_alert_second_sent_at);
        $this->assertDatabaseHas('platform_activity_log', [
            'action' => 'connection_alert_sent', 'tenant_id' => $tenantData['tenant']->id,
        ]);
    }

    public function test_a_chatbot_still_within_its_grace_window_gets_no_alert(): void
    {
        $tenantData = $this->makeTenant();
        $chatbotId = $this->makeUnconnectedChatbot($tenantData, now()->subHours(10)); // under 48h

        $this->artisan('haman:notify-unconnected-chatbots')->run();

        $entry = ChatbotIndexEntry::find($chatbotId);
        $this->assertNull($entry->connection_alert_first_sent_at);
    }

    public function test_a_second_run_does_not_resend_the_first_alert(): void
    {
        $tenantData = $this->makeTenant();
        $chatbotId = $this->makeUnconnectedChatbot($tenantData, now()->subHours(50));

        $this->artisan('haman:notify-unconnected-chatbots')->run();
        $firstSentAt = ChatbotIndexEntry::find($chatbotId)->connection_alert_first_sent_at;

        $this->artisan('haman:notify-unconnected-chatbots')->run();

        $this->assertEquals(
            $firstSentAt->toDateTimeString(),
            ChatbotIndexEntry::find($chatbotId)->connection_alert_first_sent_at->toDateTimeString(),
            'A chatbot that already got its first alert must not get a second "first" alert.'
        );
        $this->assertEquals(1, DB::table('platform_activity_log')
            ->where('action', 'connection_alert_sent')->count());
    }

    public function test_the_repeat_alert_fires_only_after_its_own_later_threshold(): void
    {
        $tenantData = $this->makeTenant();
        $chatbotId = $this->makeUnconnectedChatbot($tenantData, now()->subDays(8)); // default repeat threshold: 7d
        ChatbotIndexEntry::where('chatbot_id', $chatbotId)
            ->update(['connection_alert_first_sent_at' => now()->subDays(6)]);

        $this->artisan('haman:notify-unconnected-chatbots')->run();

        $entry = ChatbotIndexEntry::find($chatbotId);
        $this->assertNotNull($entry->connection_alert_second_sent_at);
    }

    public function test_a_chatbot_that_has_already_connected_is_left_alone(): void
    {
        $tenantData = $this->makeTenant();
        $chatbotId = $this->makeUnconnectedChatbot($tenantData, now()->subHours(50));
        ApiKey::create([
            'tenant_id' => $tenantData['tenant']->id, 'chatbot_id' => $chatbotId, 'name' => 'Plugin key',
            'key_prefix' => 'hfp_' . Str::random(8), 'key_hash' => password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]),
            'scopes' => ['sync'], 'last_used_at' => now(),
        ]);

        $this->artisan('haman:notify-unconnected-chatbots')->run();

        $this->assertNull(ChatbotIndexEntry::find($chatbotId)->connection_alert_first_sent_at);
        $this->assertDatabaseMissing('platform_activity_log', ['action' => 'connection_alert_sent']);
    }
}
