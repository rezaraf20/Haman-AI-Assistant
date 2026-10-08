<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\{ApiKey, Plan, Tenant, User};
use App\Services\TenantService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The onboarding checklist's "plugin installed" row used to sit there
 * unchecked forever with nothing actionable attached to it — no setup
 * steps, no key, nothing a merchant could act on (2026-10-08 investigation,
 * triggered by khonehrangi.ir's plugin never connecting). This guards the
 * fix: a chatbot stuck on that step now gets a step-by-step guide and its
 * own real, copyable API key inline.
 */
class OnboardingConnectionGuideTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Support\Money::forget();
    }

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
            'tenant_id' => $tenant->id, 'email' => Str::random(10) . '@example.test',
            'password' => bcrypt('irrelevant'), 'password_hash' => bcrypt('irrelevant'),
            'name' => 'Test Customer', 'role' => 'owner', 'email_verified_at' => now(),
        ]);

        return compact('tenant', 'schema', 'user');
    }

    public function test_an_unconnected_chatbot_shows_the_setup_guide_and_the_real_api_key(): void
    {
        ['tenant' => $tenant, 'schema' => $schema, 'user' => $user] = $this->makeTenantWithUser();

        $chatbotId = (string) Str::uuid();
        DB::table('chatbot_index')->insert([
            'chatbot_id' => $chatbotId, 'tenant_id' => $tenant->id, 'schema_name' => $schema,
            'is_active' => true, 'name' => 'Test Bot', 'primary_domain' => null,
        ]);

        [, $raw] = ApiKey::generate($tenant->id, $chatbotId, 'Plugin key', $user->id);

        $response = $this->actingAs($user, 'web')->get('/portal');
        $response->assertOk();
        // The checklist is still showing (onboarding is not complete) ...
        $response->assertSee(__('dashboard.onboarding_title'));
        // ... and the plugin-installed row now explains what to do and
        // gives the merchant the exact key to paste, not just an unchecked box.
        $response->assertSee(__('dashboard.onboarding_awaiting_connection'));
        $response->assertSee($raw);
    }

    public function test_the_guide_disappears_once_the_plugin_has_actually_connected(): void
    {
        ['tenant' => $tenant, 'schema' => $schema, 'user' => $user] = $this->makeTenantWithUser();

        $chatbotId = (string) Str::uuid();
        DB::table('chatbot_index')->insert([
            'chatbot_id' => $chatbotId, 'tenant_id' => $tenant->id, 'schema_name' => $schema,
            'is_active' => true, 'name' => 'Test Bot', 'primary_domain' => null,
        ]);
        ApiKey::create([
            'tenant_id' => $tenant->id, 'chatbot_id' => $chatbotId, 'name' => 'Plugin key',
            'key_prefix' => 'hfp_' . Str::random(8), 'key_hash' => password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]),
            'scopes' => ['sync'], 'last_used_at' => now(),
        ]);

        $response = $this->actingAs($user, 'web')->get('/portal');
        $response->assertOk();
        // Onboarding is still incomplete overall (no sync/conversation yet),
        // so the checklist itself is still showing ...
        $response->assertSee(__('dashboard.onboarding_title'));
        // ... but the connection guide specifically must be gone: the plugin
        // already connected, so there is nothing left to walk the merchant
        // through for this step.
        $response->assertDontSee(__('dashboard.onboarding_awaiting_connection'));
    }
}
