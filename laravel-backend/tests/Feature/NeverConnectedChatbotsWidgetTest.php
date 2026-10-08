<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\{ApiKey, Plan, Tenant, User};
use App\Services\TenantService;
use Illuminate\Support\Facades\{Cache, DB};
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The admin's "chatbots that have never connected" widget — triggered by
 * discovering khonehrangi.ir's plugin had never once authenticated, with no
 * admin-visible signal anywhere. Doubles as a churn list and the admin's
 * own call list (2026-10-08 request): see NeverConnectedChatbots's own
 * docblock for exactly which three signals qualify a row.
 */
class NeverConnectedChatbotsWidgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function makeAdmin(): User
    {
        $admin = User::create([
            'email' => Str::random(10) . '@example.test',
            'password' => bcrypt('irrelevant'), 'password_hash' => bcrypt('irrelevant'),
            'name' => 'Test Admin', 'role' => 'owner', 'email_verified_at' => now(),
        ]);
        $admin->is_platform_admin = true;
        $admin->save();

        return $admin;
    }

    private function makeTenant(string $name): array
    {
        $plan = Plan::create([
            'name' => 'Test Plan', 'slug' => 'test-' . Str::random(8),
            'price_monthly' => 0, 'max_chatbots' => 1, 'max_tokens_monthly' => 1000000,
            'is_active' => true, 'sort_order' => 0,
        ]);
        $tenant = Tenant::create([
            'slug' => 'test-' . Str::random(8), 'name' => $name,
            'email' => Str::random(12) . '@example.test', 'plan_id' => $plan->id,
            'schema_name' => 'placeholder', 'status' => 'active', 'trial_ends_at' => now()->addDays(14),
        ]);
        $schema = 'tenant_' . str_replace('-', '', $tenant->id);
        $tenant->update(['schema_name' => $schema]);
        app(TenantService::class)->createSchema($schema);

        return compact('tenant', 'schema');
    }

    public function test_a_chatbot_that_never_connected_past_its_grace_period_is_listed(): void
    {
        $admin = $this->makeAdmin();
        ['tenant' => $tenant, 'schema' => $schema] = $this->makeTenant('Stale Chatbot Shop');

        $chatbotId = (string) Str::uuid();
        DB::table('chatbot_index')->insert([
            'chatbot_id' => $chatbotId, 'tenant_id' => $tenant->id, 'schema_name' => $schema,
            'is_active' => true, 'name' => 'Stale Bot', 'primary_domain' => 'stale.example.test',
            'created_at' => now()->subDays(10),
        ]);
        // No api_keys row and no sync_jobs row at all — the "never touched
        // anything" case, not even a key that failed to authenticate.

        $response = $this->actingAs($admin, 'web')->get('/admin');
        $response->assertOk();
        $response->assertSee('Stale Chatbot Shop');
        $response->assertSee('stale.example.test');
    }

    public function test_a_connected_and_actively_syncing_chatbot_is_not_listed(): void
    {
        $admin = $this->makeAdmin();
        ['tenant' => $tenant, 'schema' => $schema] = $this->makeTenant('Healthy Chatbot Shop');

        $chatbotId = (string) Str::uuid();
        DB::table('chatbot_index')->insert([
            'chatbot_id' => $chatbotId, 'tenant_id' => $tenant->id, 'schema_name' => $schema,
            'is_active' => true, 'name' => 'Healthy Bot', 'primary_domain' => 'healthy.example.test',
            'created_at' => now()->subDays(10),
        ]);
        ApiKey::create([
            'tenant_id' => $tenant->id, 'chatbot_id' => $chatbotId, 'name' => 'Plugin Key',
            'key_prefix' => 'hfp_' . Str::random(8), 'key_hash' => password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]),
            'scopes' => ['sync'], 'last_used_at' => now(),
        ]);
        DB::statement("SET search_path TO {$schema}, public");
        DB::table('chatbots')->insert([
            'id' => $chatbotId, 'name' => 'Healthy Bot', 'type' => 'support', 'status' => 'active',
            'is_active' => true, 'language' => 'fa', 'created_at' => now()->subDays(10), 'updated_at' => now(),
        ]);
        DB::table('sync_jobs')->insert([
            'id' => (string) Str::uuid(), 'chatbot_id' => $chatbotId, 'job_type' => 'products',
            'triggered_by' => 'plugin', 'status' => 'completed', 'items_total' => 1,
            'created_at' => now(),
        ]);
        DB::statement('SET search_path TO public');

        $response = $this->actingAs($admin, 'web')->get('/admin');
        $response->assertOk();
        $response->assertDontSee('Healthy Chatbot Shop');
    }

    public function test_a_brand_new_chatbot_still_within_its_grace_period_is_not_listed(): void
    {
        $admin = $this->makeAdmin();
        ['tenant' => $tenant, 'schema' => $schema] = $this->makeTenant('Brand New Shop');

        $chatbotId = (string) Str::uuid();
        DB::table('chatbot_index')->insert([
            'chatbot_id' => $chatbotId, 'tenant_id' => $tenant->id, 'schema_name' => $schema,
            'is_active' => true, 'name' => 'New Bot', 'primary_domain' => 'brandnew.example.test',
            'created_at' => now(),
        ]);
        // Never connected, zero syncs — but created moments ago, so it must
        // not show up as if it were already a problem.

        $response = $this->actingAs($admin, 'web')->get('/admin');
        $response->assertOk();
        $response->assertDontSee('Brand New Shop');
    }
}
