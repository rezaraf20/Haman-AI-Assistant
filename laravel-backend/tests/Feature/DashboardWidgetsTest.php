<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\{User, Tenant, Plan};
use App\Services\TenantService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Guards two things the admin explicitly asked for:
 *  1. A brand-new tenant with zero data sees the onboarding checklist, not
 *     empty charts (OnboardingChecklist::canView() / CustomerOnboarding).
 *  2. Neither dashboard explodes into an N+1 query storm — widgets read from
 *     analytics_daily/platform_daily_stats (pre-aggregated by
 *     AggregateAnalyticsJob) instead of summing raw messages per request.
 */
class DashboardWidgetsTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenantWithUser(): array
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
        ]);
        $schema = 'tenant_' . str_replace('-', '', $tenant->id);
        $tenant->update(['schema_name' => $schema]);
        app(TenantService::class)->createSchema($schema);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'email' => Str::random(10) . '@example.test',
            'password' => bcrypt('irrelevant'),
            'password_hash' => bcrypt('irrelevant'),
            'name' => 'Test Customer',
            'role' => 'owner',
            'email_verified_at' => now(),
        ]);

        return compact('tenant', 'schema', 'user');
    }

    public function test_brand_new_tenant_sees_onboarding_checklist_not_empty_charts(): void
    {
        ['user' => $user] = $this->makeTenantWithUser();

        $response = $this->actingAs($user, 'web')->get('/portal');
        $response->assertOk();

        // The checklist's own copy, and the specific line items — these only
        // render when CustomerOnboarding::isComplete() is false.
        $response->assertSee(__('dashboard.onboarding_title'));
        $response->assertSee(__('dashboard.onboarding_chatbot_created'));

        // The real dashboard's stat cards must NOT render alongside it —
        // this label only appears in CustomerStatsOverview, which is gated
        // to canView() === onboarding complete.
        $response->assertDontSee(__('dashboard.customer_stats_wallet_balance'));
    }

    public function test_completed_onboarding_shows_real_dashboard_not_checklist(): void
    {
        ['tenant' => $tenant, 'schema' => $schema, 'user' => $user] = $this->makeTenantWithUser();

        $chatbotId = (string) \Illuminate\Support\Str::uuid();
        DB::statement("SET search_path TO {$schema}, public");
        DB::table('chatbots')->insert([
            'id' => $chatbotId, 'name' => 'Test Bot', 'type' => 'support', 'status' => 'active',
            'is_active' => true, 'language' => 'en', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $convId = (string) \Illuminate\Support\Str::uuid();
        DB::table('conversations')->insert([
            'id' => $convId, 'chatbot_id' => $chatbotId, 'session_id' => 'sess-1',
            'status' => 'active', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sync_jobs')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'chatbot_id' => $chatbotId,
            'job_type' => 'pages', 'status' => 'completed', 'items_total' => 1, 'items_processed' => 1,
            'created_at' => now(),
        ]);
        DB::statement('SET search_path TO public');

        DB::table('chatbot_index')->insert([
            'chatbot_id' => $chatbotId, 'tenant_id' => $tenant->id, 'schema_name' => $schema,
            'is_active' => true, 'name' => 'Test Bot', 'primary_domain' => null,
        ]);

        \App\Models\ApiKey::create([
            'tenant_id' => $tenant->id, 'chatbot_id' => $chatbotId, 'name' => 'Test Key',
            'key_prefix' => 'hfp_test1234', 'key_hash' => password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]),
            'scopes' => ['sync'], 'last_used_at' => now(),
        ]);

        $response = $this->actingAs($user, 'web')->get('/portal');
        $response->assertOk();
        $response->assertDontSee(__('dashboard.onboarding_title'));
        $response->assertSee(__('dashboard.customer_stats_wallet_balance'));
    }

    public function test_admin_dashboard_query_count_is_bounded(): void
    {
        $admin = User::create([
            'email' => Str::random(10) . '@example.test',
            'password' => bcrypt('irrelevant'), 'password_hash' => bcrypt('irrelevant'),
            'name' => 'Test Admin', 'role' => 'owner', 'email_verified_at' => now(),
        ]);

        // A couple of real tenants so the per-tenant widgets (FailedSyncsTable)
        // aren't measuring a trivially-empty-table best case.
        $this->makeTenantWithUser();
        $this->makeTenantWithUser();

        DB::enableQueryLog();
        $response = $this->actingAs($admin, 'web')->get('/admin');
        $response->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(15, $count, "Admin dashboard ran {$count} queries (budget: <15). Query log: " . json_encode(array_column(DB::getQueryLog(), 'query')));
    }

    public function test_customer_dashboard_query_count_is_bounded(): void
    {
        ['tenant' => $tenant, 'schema' => $schema, 'user' => $user] = $this->makeTenantWithUser();

        $chatbotId = (string) \Illuminate\Support\Str::uuid();
        DB::statement("SET search_path TO {$schema}, public");
        DB::table('chatbots')->insert([
            'id' => $chatbotId, 'name' => 'Test Bot', 'type' => 'support', 'status' => 'active',
            'is_active' => true, 'language' => 'en', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('conversations')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'chatbot_id' => $chatbotId, 'session_id' => 'sess-1',
            'status' => 'active', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sync_jobs')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'chatbot_id' => $chatbotId,
            'job_type' => 'pages', 'status' => 'completed', 'items_total' => 1, 'items_processed' => 1,
            'created_at' => now(),
        ]);
        DB::statement('SET search_path TO public');

        DB::table('chatbot_index')->insert([
            'chatbot_id' => $chatbotId, 'tenant_id' => $tenant->id, 'schema_name' => $schema,
            'is_active' => true, 'name' => 'Test Bot', 'primary_domain' => null,
        ]);
        \App\Models\ApiKey::create([
            'tenant_id' => $tenant->id, 'chatbot_id' => $chatbotId, 'name' => 'Test Key',
            'key_prefix' => 'hfp_test5678', 'key_hash' => password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]),
            'scopes' => ['sync'], 'last_used_at' => now(),
        ]);

        DB::enableQueryLog();
        $response = $this->actingAs($user, 'web')->get('/portal');
        $response->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(15, $count, "Customer dashboard ran {$count} queries (budget: <15). Query log: " . json_encode(array_column(DB::getQueryLog(), 'query')));
    }
}
