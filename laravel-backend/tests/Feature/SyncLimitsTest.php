<?php
namespace Tests\Feature;

use App\Models\{ApiKey, Plan, Tenant};
use App\Services\TenantService;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The plugin batches by the platform's own item limit, which it reads from
 * these responses — so changing limits.sync_items_per_request in Admin →
 * Settings reaches every installed plugin (2.7.2+) without a release. Before
 * this the plugin batched by size only and the platform refused any batch
 * of more than 50: khonehrangi.ir delivered 6 of its 173 products.
 */
class SyncLimitsTest extends TestCase
{
    use RefreshDatabase;

    private function makeKey(): array
    {
        $plan = Plan::create(['name' => 'P', 'slug' => 'p-' . Str::random(6), 'price_monthly' => 0, 'max_chatbots' => 1, 'max_tokens_monthly' => 1000000, 'is_active' => true, 'sort_order' => 0]);
        $tenant = Tenant::create(['slug' => 't-' . Str::random(6), 'name' => 'Shop', 'email' => Str::random(8) . '@example.test', 'plan_id' => $plan->id, 'schema_name' => 'placeholder', 'status' => 'active']);
        $schema = 'tenant_' . str_replace('-', '', $tenant->id);
        $tenant->update(['schema_name' => $schema]);
        app(TenantService::class)->createSchema($schema);
        $bot = (string) Str::uuid();
        DB::statement("SET search_path TO {$schema}, public");
        DB::table('chatbots')->insert(['id' => $bot, 'name' => 'Bot', 'type' => 'support', 'status' => 'active', 'is_active' => true, 'language' => 'fa', 'created_at' => now(), 'updated_at' => now()]);
        DB::statement('SET search_path TO public');
        DB::table('chatbot_index')->insert(['chatbot_id' => $bot, 'tenant_id' => $tenant->id, 'schema_name' => $schema, 'is_active' => true, 'name' => 'Bot', 'primary_domain' => 'shop.example.test']);
        $raw = 'hfp_' . Str::random(32);
        ApiKey::create(['tenant_id' => $tenant->id, 'chatbot_id' => $bot, 'name' => 'K', 'key_prefix' => substr($raw, 0, 12), 'key_hash' => password_hash($raw, PASSWORD_BCRYPT, ['cost' => 4]), 'scopes' => ['read', 'write', 'sync']]);
        return [$bot, ['Authorization' => "Bearer {$raw}", 'User-Agent' => 'WordPress/7.1.4; https://shop.example.test']];
    }

    public function test_the_connection_test_and_sync_settings_carry_the_item_limit_and_follow_the_setting(): void
    {
        [$bot, $h] = $this->makeKey();

        $this->postJson('/api/v1/connection-test', [], $h)->assertOk()->assertJsonPath('limits.sync_items_per_request', 50);
        $this->getJson("/api/v1/chatbots/{$bot}/sync-settings", $h)->assertOk()->assertJsonPath('data.limits.sync_items_per_request', 50);
        $this->putJson("/api/v1/chatbots/{$bot}/sync-settings", ['post_types' => ['page']], $h)->assertOk()->assertJsonPath('data.limits.sync_items_per_request', 50);

        // Changed in the panel → what every plugin reads next.
        Settings::set('limits.sync_items_per_request', 120);
        $this->postJson('/api/v1/connection-test', [], $h)->assertJsonPath('limits.sync_items_per_request', 120);
        $this->getJson("/api/v1/chatbots/{$bot}/sync-settings", $h)->assertJsonPath('data.limits.sync_items_per_request', 120);
    }

    public function test_the_platform_still_refuses_a_batch_over_the_limit(): void
    {
        // The plugin follows the limit; the platform keeps enforcing it.
        [$bot, $h] = $this->makeKey();
        $products = array_map(fn ($i) => ['id' => $i, 'name' => "P{$i}"], range(1, 51));
        $this->postJson('/api/v1/sync/products', ['chatbot_id' => $bot, 'products' => $products], $h)->assertStatus(422);
        $this->postJson('/api/v1/sync/products', ['chatbot_id' => $bot, 'products' => array_slice($products, 0, 50)], $h)->assertStatus(202);
    }
}
