<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\{ApiKey, ChatbotIndexEntry, Plan, Tenant, User};
use App\Services\TenantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/**
 * POST /v1/connection-test — the WordPress plugin's "Test Connection" button,
 * rebuilt (2026-10-08) specifically so every attempt, success or failure,
 * leaves a row in connection_tests. Before this, a merchant's plugin could
 * fail to connect forever and leave zero trace anywhere the platform could
 * see — see ConnectionTestController's own docblock.
 */
class ConnectionTestEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenantWithKey(bool $chatbotActive = true): array
    {
        $plan = Plan::create([
            'name' => 'Test Plan', 'slug' => 'test-' . Str::random(8),
            'price_monthly' => 0, 'max_chatbots' => 1, 'max_tokens_monthly' => 1000000,
            'is_active' => true, 'sort_order' => 0,
        ]);
        $tenant = Tenant::create([
            'slug' => 'test-' . Str::random(8), 'name' => 'Shop',
            'email' => Str::random(12) . '@example.test', 'plan_id' => $plan->id,
            'schema_name' => 'placeholder', 'status' => 'active', 'trial_ends_at' => now()->addDays(14),
        ]);
        $schema = 'tenant_' . str_replace('-', '', $tenant->id);
        $tenant->update(['schema_name' => $schema]);
        app(TenantService::class)->createSchema($schema);

        $user = User::create([
            'tenant_id' => $tenant->id, 'email' => Str::random(10) . '@example.test',
            'password' => bcrypt('x'), 'password_hash' => bcrypt('x'),
            'name' => 'Owner', 'role' => 'owner', 'email_verified_at' => now(),
        ]);

        $chatbotId = (string) Str::uuid();
        ChatbotIndexEntry::create([
            'chatbot_id' => $chatbotId, 'tenant_id' => $tenant->id, 'schema_name' => $schema,
            'is_active' => $chatbotActive, 'name' => 'Bot',
        ]);

        $raw = 'hfp_' . Str::random(32);
        $key = ApiKey::create([
            'tenant_id' => $tenant->id, 'chatbot_id' => $chatbotId, 'created_by' => $user->id,
            'name' => 'WordPress Plugin', 'key_prefix' => substr($raw, 0, 12),
            'key_hash' => password_hash($raw, PASSWORD_BCRYPT, ['cost' => 4]),
            'scopes' => ['read', 'write', 'sync', 'chat'],
        ]);

        return compact('tenant', 'schema', 'user', 'key', 'raw', 'chatbotId');
    }

    private function callTest(?string $raw): \Illuminate\Testing\TestResponse
    {
        $headers = $raw ? ['Authorization' => 'Bearer ' . $raw] : [];
        return $this->postJson('/api/v1/connection-test', [], $headers);
    }

    public function test_no_key_is_rejected_and_logged(): void
    {
        $this->callTest(null)->assertStatus(401)->assertJson(['ok' => false, 'reason' => 'missing_key']);

        $this->assertDatabaseHas('connection_tests', ['outcome' => 'missing_key']);
    }

    public function test_an_unknown_key_is_rejected_and_logged(): void
    {
        $this->callTest('hfp_' . Str::random(32))->assertStatus(401)->assertJson(['ok' => false, 'reason' => 'invalid_key']);

        $this->assertDatabaseHas('connection_tests', ['outcome' => 'invalid_key']);
    }

    public function test_an_expired_key_is_reported_precisely_and_logged(): void
    {
        ['raw' => $raw, 'key' => $key, 'tenant' => $tenant] = $this->makeTenantWithKey();
        $key->update(['expires_at' => now()->subDay()]);

        $this->callTest($raw)->assertStatus(401)->assertJson(['ok' => false, 'reason' => 'key_expired']);

        $this->assertDatabaseHas('connection_tests', [
            'outcome' => 'key_expired', 'api_key_id' => $key->id, 'tenant_id' => $tenant->id,
        ]);
    }

    public function test_a_suspended_chatbot_is_reported_precisely_and_logged(): void
    {
        ['raw' => $raw, 'tenant' => $tenant, 'chatbotId' => $chatbotId] = $this->makeTenantWithKey(chatbotActive: false);

        $this->callTest($raw)->assertStatus(403)->assertJson(['ok' => false, 'reason' => 'chatbot_suspended']);

        $this->assertDatabaseHas('connection_tests', [
            'outcome' => 'chatbot_suspended', 'tenant_id' => $tenant->id, 'chatbot_id' => $chatbotId,
        ]);
    }

    public function test_a_real_success_is_logged_and_touches_last_used_at(): void
    {
        ['raw' => $raw, 'key' => $key, 'tenant' => $tenant] = $this->makeTenantWithKey();
        $this->assertNull($key->last_used_at, 'Precondition: never used yet.');

        $this->callTest($raw)->assertOk()->assertJson(['ok' => true, 'reason' => 'success']);

        $this->assertDatabaseHas('connection_tests', ['outcome' => 'success', 'tenant_id' => $tenant->id]);
        $this->assertNotNull($key->fresh()->last_used_at, 'A successful test counts as real plugin activity.');
    }

    public function test_every_outcome_records_the_caller_ip(): void
    {
        $this->callTest(null);

        $row = \App\Models\ConnectionTest::latest('created_at')->first();
        $this->assertNotNull($row->ip, 'The admin needs the IP to tell "tried and failed" apart from a drive-by probe.');
    }
}
