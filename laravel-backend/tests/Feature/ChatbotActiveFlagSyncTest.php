<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\{ChatbotIndexEntry, Plan};
use App\Models\Tenant\Message;
use App\Services\TenantService;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Artisan, DB, Mail};
use Illuminate\Support\Str;

/**
 * chatbot_index.is_active is the source of truth — it's the one
 * ValidateChatbotDomain actually gates real chat requests on. The
 * tenant-schema chatbots.is_active column is a display mirror the
 * admin/customer panel queries read, and three code paths
 * (ExpireOverdueChatbotsCommand, EnforceTrialMessageLimitCommand,
 * ChatbotResource's admin suspend/reactivate actions) used to update
 * chatbot_index alone, leaving that mirror stale forever. Every status
 * change now goes through TenantService::setChatbotActive() instead of
 * either table being written by hand — this asserts the two never diverge,
 * for every operation that flips the flag.
 */
class ChatbotActiveFlagSyncTest extends TestCase
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

    private function makeActiveTrialChatbot(): array
    {
        $result = app(TenantService::class)->registerViaPhone([
            'phone' => '0912' . random_int(1000000, 9999999), 'first_name' => 'Sync', 'last_name' => 'Test',
            'email' => Str::random(10) . '@example.test', 'national_id' => null, 'address' => null,
        ]);
        $tenant = $result['tenant'];
        $index = ChatbotIndexEntry::where('tenant_id', $tenant->id)->first();
        return ['tenant' => $tenant, 'index' => $index];
    }

    private function tenantSchemaIsActive(string $schema, string $chatbotId): bool
    {
        DB::statement("SET search_path TO {$schema}, public");
        $active = (bool) DB::table('chatbots')->where('id', $chatbotId)->value('is_active');
        DB::statement('SET search_path TO public');
        return $active;
    }

    private function assertBothTablesAgree(string $schema, string $chatbotId, bool $expected, string $context): void
    {
        $index = ChatbotIndexEntry::where('chatbot_id', $chatbotId)->first();
        $this->assertSame($expected, (bool) $index->is_active, "{$context}: chatbot_index.is_active is not what was expected");
        $this->assertSame($expected, $this->tenantSchemaIsActive($schema, $chatbotId), "{$context}: the tenant-schema chatbots.is_active mirror has diverged from chatbot_index");
    }

    public function test_expiring_overdue_chatbots_keeps_both_tables_in_sync(): void
    {
        ['tenant' => $tenant, 'index' => $index] = $this->makeActiveTrialChatbot();
        $this->assertBothTablesAgree($tenant->schema_name, $index->chatbot_id, true, 'before expiry');

        $index->update(['expires_at' => now()->subDay()]);
        Artisan::call('chatbots:expire-overdue');

        $this->assertBothTablesAgree($tenant->schema_name, $index->chatbot_id, false, 'after expiry');
    }

    public function test_enforcing_the_trial_message_limit_keeps_both_tables_in_sync(): void
    {
        Settings::set('limits.trial_chatbot_message_limit', 2);
        ['tenant' => $tenant, 'index' => $index] = $this->makeActiveTrialChatbot();
        $this->assertBothTablesAgree($tenant->schema_name, $index->chatbot_id, true, 'before hitting the cap');

        DB::statement("SET search_path TO {$tenant->schema_name}, public");
        $conversationId = (string) Str::uuid();
        DB::table('conversations')->insert([
            'id' => $conversationId, 'chatbot_id' => $index->chatbot_id, 'session_id' => 'sess-' . Str::random(8),
            'status' => 'active', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        for ($i = 0; $i < 2; $i++) {
            Message::create(['conversation_id' => $conversationId, 'chatbot_id' => $index->chatbot_id, 'role' => 'user', 'content' => "m{$i}"]);
        }
        DB::statement('SET search_path TO public');

        Artisan::call('trial-chatbots:enforce-message-limit');

        $this->assertBothTablesAgree($tenant->schema_name, $index->chatbot_id, false, 'after hitting the cap');
    }

    public function test_admin_suspend_and_reactivate_keep_both_tables_in_sync(): void
    {
        ['tenant' => $tenant, 'index' => $index] = $this->makeActiveTrialChatbot();

        app(TenantService::class)->setChatbotActive($index->chatbot_id, false, 'admin_suspended');
        $this->assertBothTablesAgree($tenant->schema_name, $index->chatbot_id, false, 'after admin suspend');

        app(TenantService::class)->setChatbotActive($index->chatbot_id, true, null);
        $this->assertBothTablesAgree($tenant->schema_name, $index->chatbot_id, true, 'after admin reactivate');
    }

    public function test_verifying_a_pending_email_keeps_both_tables_in_sync(): void
    {
        Settings::set('mail.host', 'smtp.example.test');
        Settings::set('mail.from_address', 'bot@example.test');
        Mail::fake();

        $result = app(TenantService::class)->registerViaEmail([
            'name' => 'Sync Email', 'email' => 'sync-email@example.test', 'password' => 'password123',
        ]);
        $tenant = $result['tenant'];
        $user = $result['user'];
        $index = ChatbotIndexEntry::where('tenant_id', $tenant->id)->first();

        $this->assertBothTablesAgree($tenant->schema_name, $index->chatbot_id, false, 'before verification');

        $user->forceFill(['email_verified_at' => now()])->save();
        app(TenantService::class)->activateChatbotsPendingVerification($tenant);

        $this->assertBothTablesAgree($tenant->schema_name, $index->chatbot_id, true, 'after verification');
    }
}
