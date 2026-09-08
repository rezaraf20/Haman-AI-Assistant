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
 * "Is this genuine?" was the most common customer question in both real
 * interviews this feature was built from — and MUST be sourced only from
 * data the seller actually entered (synced from an admin-configured
 * WooCommerce field mapping — see Hamman_Product_Sync::
 * authenticity_fields() on the plugin side), never something the model
 * infers. This guards the sync-time half: a product with these fields
 * filled gets them stored AND embedded (so retrieval can surface them);
 * a product without them gets null columns and, critically, NO misleading
 * line in the embedded content at all — nothing for the model to
 * hallucinate an answer from. The prompt-side half (rag_service.
 * _authenticity_rule()) is tested separately in
 * python-ai-service/tests/test_authenticity_rule.py.
 */
class AuthenticityFieldSyncTest extends TestCase
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

    public function test_a_product_with_authenticity_fields_filled_stores_and_embeds_them(): void
    {
        Bus::fake([EmbedDocumentJob::class]);
        ['schema' => $schema, 'chatbotId' => $chatbotId, 'rawKey' => $rawKey] = $this->makeTenantWithChatbot();

        $product = [
            'id' => 8001, 'name' => 'Genuine Leather Wallet', 'status' => 'publish',
            'authenticity_status'  => 'Genuine (verified by manufacturer)',
            'brand'                => 'Acme Leathers',
            'official_distributor' => 'Acme Iran Co.',
            'warranty_period'      => '24 months',
            'country_of_origin'    => 'Italy',
        ];
        $this->postJson('/api/v1/sync/products', ['chatbot_id' => $chatbotId, 'products' => [$product]], ['Authorization' => "Bearer {$rawKey}"])
            ->assertStatus(202);

        DB::statement("SET search_path TO {$schema}, public");
        $row = DB::table('products')->where('woo_product_id', 8001)->first();
        $doc = DB::table('documents')->where('external_id', '8001')->first();
        DB::statement('SET search_path TO public');

        $this->assertSame('Genuine (verified by manufacturer)', $row->authenticity_status);
        $this->assertSame('Acme Leathers', $row->brand);
        $this->assertSame('Acme Iran Co.', $row->official_distributor);
        $this->assertSame('24 months', $row->warranty_period);
        $this->assertSame('Italy', $row->country_of_origin);

        $this->assertStringContainsString('Authenticity Status: Genuine (verified by manufacturer)', $doc->raw_content);
        $this->assertStringContainsString('Brand: Acme Leathers', $doc->raw_content);
        $this->assertStringContainsString('Official Distributor: Acme Iran Co.', $doc->raw_content);
        $this->assertStringContainsString('Warranty Period: 24 months', $doc->raw_content);
        $this->assertStringContainsString('Country of Origin: Italy', $doc->raw_content);
    }

    public function test_a_product_with_no_authenticity_fields_has_null_columns_and_no_misleading_content_lines(): void
    {
        Bus::fake([EmbedDocumentJob::class]);
        ['schema' => $schema, 'chatbotId' => $chatbotId, 'rawKey' => $rawKey] = $this->makeTenantWithChatbot();

        $product = ['id' => 8002, 'name' => 'Unmapped Product', 'status' => 'publish'];
        $this->postJson('/api/v1/sync/products', ['chatbot_id' => $chatbotId, 'products' => [$product]], ['Authorization' => "Bearer {$rawKey}"])
            ->assertStatus(202);

        DB::statement("SET search_path TO {$schema}, public");
        $row = DB::table('products')->where('woo_product_id', 8002)->first();
        $doc = DB::table('documents')->where('external_id', '8002')->first();
        DB::statement('SET search_path TO public');

        $this->assertNull($row->authenticity_status);
        $this->assertNull($row->brand);
        $this->assertNull($row->official_distributor);
        $this->assertNull($row->warranty_period);
        $this->assertNull($row->country_of_origin);

        // Critical for the acceptance criterion: absolutely nothing in the
        // embedded content that could be misread as an authenticity claim
        // — a model can only fabricate from context it doesn't have, but
        // this guards the sync side never handing it a half-truth either
        // (e.g. an empty "Authenticity Status: " line that reads as blank
        // confirmation rather than "not recorded").
        $this->assertStringNotContainsString('Authenticity Status', $doc->raw_content);
        $this->assertStringNotContainsString('Brand:', $doc->raw_content);
        $this->assertStringNotContainsString('Official Distributor', $doc->raw_content);
        $this->assertStringNotContainsString('Warranty Period', $doc->raw_content);
        $this->assertStringNotContainsString('Country of Origin', $doc->raw_content);
    }

    public function test_a_partially_filled_product_only_embeds_the_fields_that_were_actually_set(): void
    {
        Bus::fake([EmbedDocumentJob::class]);
        ['schema' => $schema, 'chatbotId' => $chatbotId, 'rawKey' => $rawKey] = $this->makeTenantWithChatbot();

        // Only brand is mapped/filled on this store — everything else was
        // never entered, which must read as "not recorded", not "unknown
        // brand of a genuine, warrantied, domestic product".
        $product = ['id' => 8003, 'name' => 'Partially Documented Product', 'status' => 'publish', 'brand' => 'Acme'];
        $this->postJson('/api/v1/sync/products', ['chatbot_id' => $chatbotId, 'products' => [$product]], ['Authorization' => "Bearer {$rawKey}"])
            ->assertStatus(202);

        DB::statement("SET search_path TO {$schema}, public");
        $row = DB::table('products')->where('woo_product_id', 8003)->first();
        $doc = DB::table('documents')->where('external_id', '8003')->first();
        DB::statement('SET search_path TO public');

        $this->assertSame('Acme', $row->brand);
        $this->assertNull($row->authenticity_status);
        $this->assertNull($row->warranty_period);

        $this->assertStringContainsString('Brand: Acme', $doc->raw_content);
        $this->assertStringNotContainsString('Authenticity Status', $doc->raw_content);
        $this->assertStringNotContainsString('Warranty Period', $doc->raw_content);
    }
}
