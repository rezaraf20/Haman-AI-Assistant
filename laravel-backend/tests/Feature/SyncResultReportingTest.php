<?php
namespace Tests\Feature;

use App\Jobs\EmbedDocumentJob;
use App\Models\{ApiKey, Plan, Tenant};
use App\Services\TenantService;
use Illuminate\Support\Facades\{Bus, DB};
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Real reported bug: the WordPress plugin's admin "sync results" table
 * (settings-page.php) always showed zero for new/updated/skipped/deleted/
 * failed, on every sync, regardless of what actually happened — even though
 * SyncService already computed the real breakdown and saved it on the
 * SyncJob row. Root cause: SyncController::jobArr() (the API response the
 * plugin actually reads) never included that 'result' field at all, so the
 * plugin's Haman_Product_Sync/Page_Sync/Faq_Sync::sync_all() had nothing to
 * report but a flat "how many did I POST" count under a key
 * (settings-page.php's $row['new'] etc.) that never existed in what they
 * returned either.
 *
 * This covers the Laravel half of the fix — the API response now actually
 * carries the breakdown. See wordpress-plugin/haman-ai-chatbot/tests for
 * the plugin-side accumulation (Haman_Sync_Counts).
 */
class SyncResultReportingTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenantWithChatbot(): array
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

        $chatbotId = (string) Str::uuid();
        DB::statement("SET search_path TO {$schema}, public");
        DB::table('chatbots')->insert([
            'id' => $chatbotId, 'name' => 'Bot', 'type' => 'support', 'status' => 'active',
            'is_active' => true, 'language' => 'fa', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement('SET search_path TO public');
        DB::table('chatbot_index')->insert([
            'chatbot_id' => $chatbotId, 'tenant_id' => $tenant->id, 'schema_name' => $schema,
            'is_active' => true, 'name' => 'Bot', 'primary_domain' => null,
        ]);

        $rawKey = 'hfp_' . Str::random(32);
        ApiKey::create([
            'tenant_id' => $tenant->id, 'chatbot_id' => $chatbotId, 'name' => 'Test Key',
            'key_prefix' => substr($rawKey, 0, 12),
            'key_hash' => password_hash($rawKey, PASSWORD_BCRYPT, ['cost' => 4]),
            'scopes' => ['read', 'write', 'sync'],
        ]);

        return compact('tenant', 'schema', 'chatbotId', 'rawKey');
    }

    public function test_a_products_sync_response_reports_the_real_new_updated_skipped_breakdown(): void
    {
        Bus::fake([EmbedDocumentJob::class]);
        ['chatbotId' => $chatbotId, 'rawKey' => $rawKey] = $this->makeTenantWithChatbot();

        $products = [
            ['id' => 1, 'name' => 'Product One'],
            ['id' => 2, 'name' => 'Product Two'],
        ];

        $r = $this->postJson('/api/v1/sync/products', ['chatbot_id' => $chatbotId, 'products' => $products], ['Authorization' => "Bearer {$rawKey}"]);
        $r->assertStatus(202);

        // The exact regression: this key must exist at all, and it must be
        // the real per-outcome breakdown, not absent (which is what made
        // every plugin-side display of it fall back to a phantom zero).
        $r->assertJsonPath('data.result.new', 2);
        $r->assertJsonPath('data.result.updated', 0);
        $r->assertJsonPath('data.result.skipped', 0);
        $r->assertJsonPath('data.result.failed', 0);
        $r->assertJsonPath('data.items_processed', 2);

        // Re-syncing byte-for-byte identical content is a real "skipped",
        // not silently reported the same as "new".
        $r2 = $this->postJson('/api/v1/sync/products', ['chatbot_id' => $chatbotId, 'products' => $products], ['Authorization' => "Bearer {$rawKey}"]);
        $r2->assertJsonPath('data.result.new', 0);
        $r2->assertJsonPath('data.result.skipped', 2);

        // A genuine change to one product is a real "updated", not lumped
        // in with "new" or "skipped".
        $products[0]['name'] = 'Product One (renamed)';
        $r3 = $this->postJson('/api/v1/sync/products', ['chatbot_id' => $chatbotId, 'products' => $products], ['Authorization' => "Bearer {$rawKey}"]);
        $r3->assertJsonPath('data.result.updated', 1);
        $r3->assertJsonPath('data.result.skipped', 1);
    }

    public function test_a_pages_sync_response_also_reports_the_breakdown(): void
    {
        Bus::fake([EmbedDocumentJob::class]);
        ['chatbotId' => $chatbotId, 'rawKey' => $rawKey] = $this->makeTenantWithChatbot();

        $page = ['id' => 501, 'title' => 'Services', 'content' => 'What we offer.', 'url' => 'https://example.test/services', 'post_type' => 'page'];

        $r = $this->postJson('/api/v1/sync/pages', ['chatbot_id' => $chatbotId, 'pages' => [$page]], ['Authorization' => "Bearer {$rawKey}"]);

        $r->assertJsonPath('data.result.new', 1);
        $this->assertIsArray($r->json('data.result'), 'the pages endpoint must carry the same result breakdown the products endpoint does');
    }
}
