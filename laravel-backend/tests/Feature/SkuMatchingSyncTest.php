<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Tenant;
use App\Models\Plan;
use App\Models\ApiKey;
use App\Services\TenantService;
use App\Jobs\EmbedDocumentJob;
use Illuminate\Support\Facades\{DB, Bus};
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Guards the sync-time half of SKU/part-number lookup (see
 * python-ai-service/tests/test_sku_matching.py for the retrieval-side
 * half): products.sku_normalized must be populated at sync time via
 * App\Support\SkuNormalizer so rag_service.py's exact-match lookup can find
 * a customer-typed variant ("lm358-n") against the catalog's real SKU
 * ("LM358N"). Also guards that the SKU stays in the document's searchable
 * content, since a vector-only match on a bare part number is unreliable.
 */
class SkuMatchingSyncTest extends TestCase
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
            'slug' => 'test-' . Str::random(8), 'name' => 'Test Tenant',
            'email' => Str::random(12) . '@example.test', 'plan_id' => $plan->id,
            'schema_name' => 'placeholder', 'status' => 'active', 'trial_ends_at' => now()->addDays(14),
        ]);
        $schema = 'tenant_' . str_replace('-', '', $tenant->id);
        $tenant->update(['schema_name' => $schema]);
        app(TenantService::class)->createSchema($schema);

        $chatbotId = (string) Str::uuid();
        DB::statement("SET search_path TO {$schema}, public");
        DB::table('chatbots')->insert([
            'id' => $chatbotId, 'name' => 'Test Bot', 'type' => 'support', 'status' => 'active',
            'is_active' => true, 'language' => 'en', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement('SET search_path TO public');

        DB::table('chatbot_index')->insert([
            'chatbot_id' => $chatbotId, 'tenant_id' => $tenant->id, 'schema_name' => $schema,
            'is_active' => true, 'name' => 'Test Bot', 'primary_domain' => null,
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

    public function test_syncing_a_product_populates_normalized_sku(): void
    {
        Bus::fake([EmbedDocumentJob::class]);
        ['schema' => $schema, 'chatbotId' => $chatbotId, 'rawKey' => $rawKey] = $this->makeTenantWithChatbot();

        $product = ['id' => 7001, 'name' => 'LM358 Op-Amp', 'sku' => 'LM358-N', 'price' => 15000, 'status' => 'publish'];
        $this->postJson('/api/v1/sync/products', ['chatbot_id' => $chatbotId, 'products' => [$product]], ['Authorization' => "Bearer {$rawKey}"])
            ->assertStatus(202);

        DB::statement("SET search_path TO {$schema}, public");
        $row = DB::table('products')->where('woo_product_id', 7001)->first();
        DB::statement('SET search_path TO public');

        $this->assertSame('LM358-N', $row->sku, 'The original, unmodified SKU should still be stored as-is.');
        $this->assertSame('LM358N', $row->sku_normalized, 'Hyphens must be stripped and the value uppercased for exact-match lookup.');
    }

    public function test_lowercase_and_hyphenated_variants_normalize_identically(): void
    {
        Bus::fake([EmbedDocumentJob::class]);
        ['schema' => $schema, 'chatbotId' => $chatbotId, 'rawKey' => $rawKey] = $this->makeTenantWithChatbot();

        $products = [
            ['id' => 7101, 'name' => 'Part A', 'sku' => 'ABC1234', 'status' => 'publish'],
            ['id' => 7102, 'name' => 'Part B', 'sku' => 'abc-1234', 'status' => 'publish'],
            ['id' => 7103, 'name' => 'Part C', 'sku' => 'ABC 1234', 'status' => 'publish'],
        ];
        $this->postJson('/api/v1/sync/products', ['chatbot_id' => $chatbotId, 'products' => $products], ['Authorization' => "Bearer {$rawKey}"])
            ->assertStatus(202);

        DB::statement("SET search_path TO {$schema}, public");
        $normalized = DB::table('products')->whereIn('woo_product_id', [7101, 7102, 7103])->pluck('sku_normalized')->unique()->values();
        DB::statement('SET search_path TO public');

        $this->assertCount(1, $normalized, 'All three SKU spellings must converge to the same normalized value.');
        $this->assertSame('ABC1234', $normalized[0]);
    }

    public function test_document_content_still_includes_the_sku_for_embedding(): void
    {
        Bus::fake([EmbedDocumentJob::class]);
        ['schema' => $schema, 'chatbotId' => $chatbotId, 'rawKey' => $rawKey] = $this->makeTenantWithChatbot();

        $product = ['id' => 7201, 'name' => 'Widget', 'sku' => 'WID-9999', 'status' => 'publish'];
        $this->postJson('/api/v1/sync/products', ['chatbot_id' => $chatbotId, 'products' => [$product]], ['Authorization' => "Bearer {$rawKey}"])
            ->assertStatus(202);

        DB::statement("SET search_path TO {$schema}, public");
        $doc = DB::table('documents')->where('external_id', '7201')->first();
        DB::statement('SET search_path TO public');

        $this->assertStringContainsString('WID-9999', $doc->raw_content);
    }

    public function test_missing_sku_leaves_normalized_column_null_not_an_error(): void
    {
        Bus::fake([EmbedDocumentJob::class]);
        ['schema' => $schema, 'chatbotId' => $chatbotId, 'rawKey' => $rawKey] = $this->makeTenantWithChatbot();

        $product = ['id' => 7301, 'name' => 'No SKU Product', 'status' => 'publish'];
        $this->postJson('/api/v1/sync/products', ['chatbot_id' => $chatbotId, 'products' => [$product]], ['Authorization' => "Bearer {$rawKey}"])
            ->assertStatus(202);

        DB::statement("SET search_path TO {$schema}, public");
        $row = DB::table('products')->where('woo_product_id', 7301)->first();
        DB::statement('SET search_path TO public');

        $this->assertNull($row->sku_normalized);
    }
}
