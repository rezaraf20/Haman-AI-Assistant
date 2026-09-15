<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\{ApiKey, Tenant, Plan, User};
use App\Services\TenantService;
use App\Support\CustomerOnboarding;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * last_used_at was read by CustomerOnboarding to decide "plugin
 * installed" but written by nothing, so the onboarding checklist told
 * every customer their plugin was missing forever — including ones whose
 * plugin was syncing at that moment. A new customer reads that as "the
 * product doesn't work".
 *
 * The write is throttled, because the plugin can call these endpoints
 * constantly and this column only needs minute-level accuracy.
 */
class ApiKeyLastUsedTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenantWithKey(): array
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

        $raw = 'hfp_' . Str::random(32);
        $key = ApiKey::create([
            'tenant_id'  => $tenant->id,
            'created_by' => $user->id,
            'name'       => 'WordPress Plugin',
            'key_prefix' => substr($raw, 0, 12),
            'key_hash'   => password_hash($raw, PASSWORD_BCRYPT, ['cost' => 4]),
            'scopes'     => ['read', 'write', 'sync', 'chat'],
        ]);

        return compact('tenant', 'schema', 'user', 'key', 'raw');
    }

    /**
     * Deliberately NOT /api/v1/chatbots: that URI is registered twice, and
     * the Sanctum apiResource in the dashboard group overwrites the
     * api-key one in the route lookup, so an API key gets 401 there. This
     * endpoint is only ever registered behind auth.apikey.
     */
    private function callApi(string $raw)
    {
        return $this->getJson('/api/v1/tenant/webhook-secret', ['Authorization' => 'Bearer ' . $raw]);
    }

    public function test_a_successful_api_call_records_that_the_key_was_used(): void
    {
        ['key' => $key, 'raw' => $raw] = $this->makeTenantWithKey();
        $this->assertNull($key->last_used_at, 'Precondition: nothing has written this yet.');

        $response = $this->callApi($raw);
        $response->assertOk();

        $this->assertNotNull($key->fresh()->last_used_at);
    }

    public function test_the_calling_ip_is_recorded_too(): void
    {
        ['key' => $key, 'raw' => $raw] = $this->makeTenantWithKey();

        $this->callApi($raw);

        $this->assertNotNull($key->fresh()->last_used_ip);
    }

    public function test_a_rejected_key_records_nothing(): void
    {
        ['key' => $key] = $this->makeTenantWithKey();

        $this->callApi('hfp_' . Str::random(32))->assertStatus(401);

        $this->assertNull($key->fresh()->last_used_at);
    }

    public function test_a_second_call_moments_later_does_not_write_again(): void
    {
        ['key' => $key, 'raw' => $raw] = $this->makeTenantWithKey();

        $this->callApi($raw);
        $first = $key->fresh()->last_used_at;

        $this->callApi($raw);

        $this->assertEquals(
            $first->toDateTimeString(),
            $key->fresh()->last_used_at->toDateTimeString(),
            'A busy plugin must not cause a write per request.'
        );
    }

    public function test_a_call_after_the_throttle_window_writes_again(): void
    {
        ['key' => $key, 'raw' => $raw] = $this->makeTenantWithKey();

        $this->callApi($raw);
        $key->updateQuietly(['last_used_at' => now()->subHour()]);

        $this->callApi($raw);

        $this->assertTrue(
            $key->fresh()->last_used_at->gt(now()->subMinute()),
            'Once the value is genuinely stale it must be refreshed.'
        );
    }

    // ── The thing this was actually breaking ────────────────────────────

    public function test_the_onboarding_checklist_now_sees_an_installed_plugin(): void
    {
        ['user' => $user, 'raw' => $raw] = $this->makeTenantWithKey();

        $before = CustomerOnboarding::status($user->tenant);
        $this->assertFalse($before['plugin_installed'], 'Precondition: not used yet.');

        $this->callApi($raw);

        // status() is cached for five minutes, so the checklist a merchant
        // sees can lag their first successful plugin call by that much.
        // Acceptable for a checklist — but the cache has to be dropped here
        // or this test would only ever re-read its own precondition.
        CustomerOnboarding::forget($user->tenant);

        $after = CustomerOnboarding::status($user->tenant->fresh());
        $this->assertTrue($after['plugin_installed'], 'A plugin that has authenticated is installed.');
    }

    // ── Backfill for keys already in use ────────────────────────────────

    public function test_the_backfill_reconstructs_a_date_from_a_real_sync_job(): void
    {
        ['tenant' => $tenant, 'schema' => $schema, 'key' => $key] = $this->makeTenantWithKey();

        $chatbotId = (string) Str::uuid();
        DB::statement("SET search_path TO {$schema}, public");
        DB::table('chatbots')->insert([
            'id' => $chatbotId, 'name' => 'Bot', 'type' => 'support', 'status' => 'active',
            'is_active' => true, 'language' => 'fa', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sync_jobs')->insert([
            'id' => (string) Str::uuid(), 'chatbot_id' => $chatbotId, 'job_type' => 'products',
            'triggered_by' => 'plugin', 'status' => 'completed', 'items_total' => 1,
            'started_at' => now()->subDays(3), 'created_at' => now()->subDays(3),
        ]);
        DB::statement('SET search_path TO public');

        $this->artisan('hamman:backfill-api-key-usage')->run();

        $this->assertNotNull($key->fresh()->last_used_at, 'A completed sync proves the key was used.');
    }

    public function test_the_backfill_leaves_a_key_with_no_evidence_alone(): void
    {
        ['key' => $key] = $this->makeTenantWithKey();

        $this->artisan('hamman:backfill-api-key-usage')->run();

        $this->assertNull(
            $key->fresh()->last_used_at,
            'Inventing a date would defeat the checklist this exists to fix.'
        );
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        ['schema' => $schema, 'key' => $key] = $this->makeTenantWithKey();

        $chatbotId = (string) Str::uuid();
        DB::statement("SET search_path TO {$schema}, public");
        DB::table('chatbots')->insert([
            'id' => $chatbotId, 'name' => 'Bot', 'type' => 'support', 'status' => 'active',
            'is_active' => true, 'language' => 'fa', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sync_jobs')->insert([
            'id' => (string) Str::uuid(), 'chatbot_id' => $chatbotId, 'job_type' => 'products',
            'triggered_by' => 'plugin', 'status' => 'completed', 'items_total' => 1,
            'started_at' => now()->subDay(), 'created_at' => now()->subDay(),
        ]);
        DB::statement('SET search_path TO public');

        $this->artisan('hamman:backfill-api-key-usage', ['--dry-run' => true])->run();

        $this->assertNull($key->fresh()->last_used_at);
    }
}
