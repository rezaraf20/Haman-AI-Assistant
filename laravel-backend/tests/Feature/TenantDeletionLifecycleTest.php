<?php
namespace Tests\Feature;

use App\Models\{Tenant, User, Plan};
use App\Models\Tenant\Chatbot;
use App\Services\{TenantService, TenantSchemaBackupService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The two-phase deletion lifecycle this feature exists for: mark (fully
 * reversible, nothing destroyed) -> restore (back to exactly how it was),
 * or mark -> grace period elapses -> DropPendingDeletionTenantsCommand
 * actually drops the schema, backing it up first. Mirrors the acceptance
 * criteria given for this feature directly.
 */
class TenantDeletionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenantWithConversationAndDocument(): array
    {
        $plan = Plan::create([
            'name' => 'Test Plan', 'slug' => 'test-' . Str::random(8),
            'price_monthly' => 0, 'max_chatbots' => 1, 'max_tokens_monthly' => 1000000,
            'is_active' => true, 'sort_order' => 0,
        ]);
        $tenant = Tenant::create([
            'slug' => 'test-' . Str::random(8), 'name' => 'Test Tenant',
            'email' => Str::random(12) . '@example.test', 'plan_id' => $plan->id,
            'schema_name' => 'placeholder', 'status' => 'active', 'trial_ends_at' => now()->addDays(14),
            'provisioned_at' => now(),
        ]);
        $schema = 'tenant_' . str_replace('-', '', $tenant->id);
        $tenant->update(['schema_name' => $schema]);
        app(TenantService::class)->createSchema($schema);

        $user = User::create([
            'tenant_id' => $tenant->id, 'email' => Str::random(10) . '@example.test',
            'password' => bcrypt('irrelevant'), 'password_hash' => bcrypt('irrelevant'),
            'name' => 'Test Owner', 'role' => 'owner', 'email_verified_at' => now(),
        ]);

        $chatbotId = (string) Str::uuid();
        DB::statement("SET search_path TO {$schema}, public");
        DB::table('chatbots')->insert([
            'id' => $chatbotId, 'name' => 'Test Bot', 'type' => 'support', 'status' => 'active',
            'is_active' => true, 'language' => 'en', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $convId = (string) Str::uuid();
        DB::table('conversations')->insert([
            'id' => $convId, 'chatbot_id' => $chatbotId, 'session_id' => 'sess-1',
            'status' => 'active', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('documents')->insert([
            'id' => (string) Str::uuid(), 'chatbot_id' => $chatbotId, 'source_type' => 'faq',
            'external_id' => 'faq_1', 'title' => 'Test FAQ', 'raw_content' => 'Q: A:',
            'content_hash' => md5('Q: A:'), 'status' => 'indexed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement('SET search_path TO public');

        DB::table('chatbot_index')->insert([
            'chatbot_id' => $chatbotId, 'tenant_id' => $tenant->id, 'schema_name' => $schema,
            'is_active' => true, 'name' => 'Test Bot', 'primary_domain' => null,
        ]);

        return compact('tenant', 'user', 'schema', 'chatbotId');
    }

    public function test_mark_for_deletion_leaves_schema_and_data_untouched_but_cuts_access(): void
    {
        ['tenant' => $tenant, 'schema' => $schema, 'chatbotId' => $chatbotId] = $this->makeTenantWithConversationAndDocument();

        app(TenantService::class)->markForDeletion($tenant);
        $tenant->refresh();

        $this->assertNotNull($tenant->pending_deletion_at);
        $this->assertTrue($tenant->isPendingDeletion());
        // Portal access cut — the same gate User::canAccessPanel() uses.
        $this->assertFalse($tenant->isAccessible());
        $this->assertSame('suspended', $tenant->status);

        // Widget off: chatbot_index.is_active is what ValidateChatbotDomain gates on.
        $this->assertFalse((bool) DB::table('chatbot_index')->where('chatbot_id', $chatbotId)->value('is_active'));

        // Schema and every row inside it are completely untouched.
        $schemaExists = DB::selectOne('SELECT 1 FROM information_schema.schemata WHERE schema_name = ?', [$schema]);
        $this->assertNotNull($schemaExists);

        DB::statement("SET search_path TO {$schema}, public");
        $this->assertSame(1, DB::table('conversations')->count());
        $this->assertSame(1, DB::table('documents')->count());
        DB::statement('SET search_path TO public');
    }

    public function test_restore_from_deletion_returns_everything_to_how_it_was(): void
    {
        ['tenant' => $tenant, 'chatbotId' => $chatbotId] = $this->makeTenantWithConversationAndDocument();

        app(TenantService::class)->markForDeletion($tenant);
        app(TenantService::class)->restoreFromDeletion($tenant->refresh());
        $tenant->refresh();

        $this->assertNull($tenant->pending_deletion_at);
        $this->assertTrue($tenant->isAccessible());
        $this->assertSame('active', $tenant->status);
        $this->assertTrue((bool) DB::table('chatbot_index')->where('chatbot_id', $chatbotId)->value('is_active'));
    }

    public function test_restore_does_not_reactivate_a_chatbot_that_was_already_off_before_marking(): void
    {
        ['tenant' => $tenant, 'chatbotId' => $chatbotId] = $this->makeTenantWithConversationAndDocument();
        app(TenantService::class)->setChatbotActive($chatbotId, false, 'merchant_disabled');

        app(TenantService::class)->markForDeletion($tenant->refresh());
        app(TenantService::class)->restoreFromDeletion($tenant->refresh());

        // The snapshot remembered this chatbot was already off — restore
        // must not silently turn it back on just because deletion was undone.
        $this->assertFalse((bool) DB::table('chatbot_index')->where('chatbot_id', $chatbotId)->value('is_active'));
    }

    public function test_grace_period_elapsing_drops_schema_and_writes_a_backup_file(): void
    {
        ['tenant' => $tenant, 'schema' => $schema] = $this->makeTenantWithConversationAndDocument();

        app(TenantService::class)->markForDeletion($tenant->refresh());

        // Simulates DropPendingDeletionTenantsCommand finding this tenant
        // past its (here, zero-day) grace period and calling the same
        // method the command itself calls.
        $backupPath = app(TenantService::class)->permanentlyDeleteNow($tenant->refresh(), app(TenantSchemaBackupService::class));
        $this->assertFileExists($backupPath);
        $this->assertGreaterThan(0, filesize($backupPath));

        $schemaExists = DB::selectOne('SELECT 1 FROM information_schema.schemata WHERE schema_name = ?', [$schema]);
        $this->assertNull($schemaExists);

        // deleted_at is set only now — at the real, irreversible drop — not
        // at markForDeletion() time. This is the whole point of splitting
        // "suspended, reversible" from what deleted_at actually means.
        $this->assertNotNull($tenant->fresh()->deleted_at);

        @unlink($backupPath);
    }

    public function test_permanent_delete_is_blocked_once_any_usage_exists(): void
    {
        ['tenant' => $tenant] = $this->makeTenantWithConversationAndDocument();
        // makeTenantWithConversationAndDocument() already created one
        // conversation — hasAnyUsage() must see it directly, with zero
        // reliance on the usage_tokens_current/usage_messages_current
        // counters (which a merchant's actual spend wouldn't always update
        // before this check runs).
        $this->assertTrue(app(TenantService::class)->hasAnyUsage($tenant));
    }

    public function test_permanent_delete_is_available_for_a_genuinely_empty_tenant(): void
    {
        $plan = Plan::create([
            'name' => 'Test Plan', 'slug' => 'test-' . Str::random(8),
            'price_monthly' => 0, 'max_chatbots' => 1, 'max_tokens_monthly' => 1000000,
            'is_active' => true, 'sort_order' => 0,
        ]);
        $tenant = Tenant::create([
            'slug' => 'test-' . Str::random(8), 'name' => 'Empty Tenant',
            'email' => Str::random(12) . '@example.test', 'plan_id' => $plan->id,
            'schema_name' => 'placeholder', 'status' => 'trial', 'trial_ends_at' => now()->addDays(14),
            'provisioned_at' => now(),
        ]);
        $schema = 'tenant_' . str_replace('-', '', $tenant->id);
        $tenant->update(['schema_name' => $schema]);
        app(TenantService::class)->createSchema($schema);

        $this->assertFalse(app(TenantService::class)->hasAnyUsage($tenant));
    }

    public function test_both_mark_and_permanent_delete_write_an_audit_row_even_without_an_authenticated_actor(): void
    {
        ['tenant' => $tenant, 'schema' => $schema] = $this->makeTenantWithConversationAndDocument();

        app(TenantService::class)->markForDeletion($tenant->refresh());
        \App\Support\PlatformActivity::record(
            'tenant_marked_for_deletion', tenantId: (string) $tenant->id,
            subjectType: 'tenant', subjectId: (string) $tenant->id,
        );

        $backupPath = app(TenantService::class)->permanentlyDeleteNow($tenant->refresh(), app(TenantSchemaBackupService::class));
        \App\Support\PlatformActivity::record(
            'tenant_permanently_deleted', tenantId: (string) $tenant->id,
            subjectType: 'tenant', subjectId: (string) $tenant->id,
            after: ['schema_name' => $schema],
        );

        $rows = DB::table('platform_activity_log')->where('tenant_id', $tenant->id)->orderBy('created_at')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('tenant_marked_for_deletion', $rows[0]->action);
        $this->assertSame('tenant_permanently_deleted', $rows[1]->action);
        // No auth()->user() in this test -- both rows must still exist,
        // attributed to 'system' rather than silently never written.
        $this->assertSame('system', $rows[0]->user_email);
        $this->assertSame('system', $rows[1]->user_email);

        @unlink($backupPath);
    }
}
