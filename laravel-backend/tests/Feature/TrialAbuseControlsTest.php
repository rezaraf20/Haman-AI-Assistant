<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Filament\Widgets\TrialTenantsTable;
use App\Models\{ChatbotIndexEntry, Plan, User};
use App\Models\Tenant\Message;
use App\Services\TenantService;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Artisan, Cache, DB};
use Illuminate\Support\Str;

/**
 * Real gap this closes: every signup gives 200 free LLM messages, and the
 * trial chatbot has no primary_domain (ValidateChatbotDomain is soft when
 * it's unset), so it's callable from anywhere — before this, an
 * unverified email got that immediately, no cost control beyond the
 * automatic per-chatbot caps that only fire after the fact.
 */
class TrialAbuseControlsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Plan::create([
            'name' => 'Free', 'slug' => 'free', 'price_monthly' => 0,
            'max_chatbots' => 1, 'max_tokens_monthly' => 100000,
            'is_active' => true, 'sort_order' => 0,
        ]);
    }

    private function makeAdmin(): User {
        $admin = User::create([
            'email' => Str::random(10) . '@example.test',
            'password' => bcrypt('irrelevant'), 'password_hash' => bcrypt('irrelevant'),
            'name' => 'Platform Admin', 'role' => 'owner', 'email_verified_at' => now(),
        ]);
        $admin->is_platform_admin = true;
        $admin->save();
        return $admin;
    }

    private function makeActiveTrialTenant(): array
    {
        $result = app(TenantService::class)->registerViaPhone([
            'phone' => '0912' . random_int(1000000, 9999999), 'first_name' => 'Cost', 'last_name' => 'Test',
            'email' => Str::random(10) . '@example.test', 'national_id' => null, 'address' => null,
        ]);
        $tenant = $result['tenant'];
        $index = ChatbotIndexEntry::where('tenant_id', $tenant->id)->first();

        return ['tenant' => $tenant, 'index' => $index];
    }

    private function addCostedMessages(string $schema, string $chatbotId, int $count, float $costEach): void
    {
        DB::statement("SET search_path TO {$schema}, public");
        $conversationId = (string) Str::uuid();
        DB::table('conversations')->insert([
            'id' => $conversationId, 'chatbot_id' => $chatbotId, 'session_id' => 'sess-' . Str::random(8),
            'status' => 'active', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        for ($i = 0; $i < $count; $i++) {
            Message::create([
                'conversation_id' => $conversationId, 'chatbot_id' => $chatbotId,
                'role' => 'user', 'content' => "message {$i}", 'cost_toman' => $costEach, 'total_tokens' => 100,
            ]);
        }
        DB::statement('SET search_path TO public');
    }

    public function test_the_admin_widget_lists_only_active_trial_chatbots_with_real_usage(): void
    {
        ['tenant' => $tenant, 'index' => $index] = $this->makeActiveTrialTenant();
        $this->addCostedMessages($tenant->schema_name, $index->chatbot_id, 3, 1500.0);

        $this->actingAs($this->makeAdmin());
        $rows = (new TrialTenantsTable())->getRows();

        $row = collect($rows)->firstWhere('chatbot_id', $index->chatbot_id);
        $this->assertNotNull($row, 'an active trial chatbot with usage must appear in the admin widget');
        $this->assertEquals(3, $row['message_count']);
        $this->assertEqualsWithDelta(4500.0, $row['cost_toman'], 0.01);
    }

    public function test_the_admin_widget_excludes_an_already_suspended_trial_chatbot(): void
    {
        ['tenant' => $tenant, 'index' => $index] = $this->makeActiveTrialTenant();
        $index->update(['is_active' => false, 'disabled_reason' => 'expired']);

        $this->actingAs($this->makeAdmin());
        $rows = (new TrialTenantsTable())->getRows();

        $this->assertNull(collect($rows)->firstWhere('chatbot_id', $index->chatbot_id), 'a suspended trial chatbot has nothing actionable left — it must not clutter the active list');
    }

    public function test_admin_can_manually_deactivate_a_trial_chatbot(): void
    {
        ['tenant' => $tenant, 'index' => $index] = $this->makeActiveTrialTenant();
        $this->actingAs($this->makeAdmin());

        (new TrialTenantsTable())->deactivate($index->chatbot_id);

        $fresh = ChatbotIndexEntry::where('chatbot_id', $index->chatbot_id)->first();
        $this->assertFalse((bool) $fresh->is_active);
        $this->assertEquals('admin_suspended', $fresh->disabled_reason);

        DB::statement("SET search_path TO {$tenant->schema_name}, public");
        $chatbotActive = DB::table('chatbots')->where('id', $index->chatbot_id)->value('is_active');
        DB::statement('SET search_path TO public');
        $this->assertFalse((bool) $chatbotActive, 'the tenant-schema chatbot row must agree with chatbot_index, not just the public one');
    }

    public function test_a_non_admin_cannot_deactivate_a_trial_chatbot(): void
    {
        ['index' => $index] = $this->makeActiveTrialTenant();
        $support = User::create([
            'email' => Str::random(10) . '@example.test',
            'password' => bcrypt('x'), 'password_hash' => bcrypt('x'),
            'name' => 'Support', 'role' => 'owner', 'email_verified_at' => now(),
        ]);
        $support->platform_role = 'support';
        $support->save();
        $this->actingAs($support);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        (new TrialTenantsTable())->deactivate($index->chatbot_id);
    }

    public function test_cost_alert_fires_once_the_daily_threshold_is_exceeded(): void
    {
        Settings::set('limits.trial_daily_cost_alert_toman', 10000);
        ['tenant' => $tenant, 'index' => $index] = $this->makeActiveTrialTenant();
        $this->addCostedMessages($tenant->schema_name, $index->chatbot_id, 1, 15000.0);
        $admin = $this->makeAdmin();

        Artisan::call('trial-chatbots:check-daily-cost');

        $this->assertEquals(1, $admin->fresh()->unreadNotifications()->count());
    }

    public function test_cost_alert_does_not_fire_under_the_threshold(): void
    {
        Settings::set('limits.trial_daily_cost_alert_toman', 10000);
        ['tenant' => $tenant, 'index' => $index] = $this->makeActiveTrialTenant();
        $this->addCostedMessages($tenant->schema_name, $index->chatbot_id, 1, 500.0);
        $admin = $this->makeAdmin();

        Artisan::call('trial-chatbots:check-daily-cost');

        $this->assertEquals(0, $admin->fresh()->unreadNotifications()->count());
    }

    public function test_cost_alert_does_not_repeat_within_the_same_day(): void
    {
        Settings::set('limits.trial_daily_cost_alert_toman', 10000);
        ['tenant' => $tenant, 'index' => $index] = $this->makeActiveTrialTenant();
        $this->addCostedMessages($tenant->schema_name, $index->chatbot_id, 1, 15000.0);
        $admin = $this->makeAdmin();

        Artisan::call('trial-chatbots:check-daily-cost');
        Artisan::call('trial-chatbots:check-daily-cost');

        $this->assertEquals(1, $admin->fresh()->unreadNotifications()->count(), 'a second run the same day must not re-notify');
    }
}
