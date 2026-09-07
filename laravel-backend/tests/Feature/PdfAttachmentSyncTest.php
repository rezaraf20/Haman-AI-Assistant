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
 * Guards the sync-time half of PDF datasheet indexing (see
 * python-ai-service/tests/test_pdf_extraction.py for the extraction/
 * embedding side): each attachment the WordPress plugin reports for a
 * product (see class-hamman-product-sync.php's product_pdf_attachments())
 * must become its own 'product_attachment' document, distinct from the
 * product's own document, so it gets its own EmbedDocumentJob and its own
 * page-aware chunking in pdf_service.py.
 */
class PdfAttachmentSyncTest extends TestCase
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

    public function test_product_with_pdf_attachment_creates_a_separate_attachment_document(): void
    {
        Bus::fake([EmbedDocumentJob::class]);
        ['schema' => $schema, 'chatbotId' => $chatbotId, 'rawKey' => $rawKey] = $this->makeTenantWithChatbot();

        $product = [
            'id' => 8001, 'name' => 'LM358 Op-Amp', 'sku' => 'LM358N', 'status' => 'publish',
            'attachments' => [
                ['url' => 'https://example.test/datasheets/lm358.pdf', 'name' => 'LM358 Datasheet'],
            ],
        ];
        $this->postJson('/api/v1/sync/products', ['chatbot_id' => $chatbotId, 'products' => [$product]], ['Authorization' => "Bearer {$rawKey}"])
            ->assertStatus(202);

        // One dispatch for the product document, one for the attachment document.
        Bus::assertDispatchedTimes(EmbedDocumentJob::class, 2);

        DB::statement("SET search_path TO {$schema}, public");
        $doc = DB::table('documents')->where('source_type', 'product_attachment')->first();
        DB::statement('SET search_path TO public');

        $this->assertNotNull($doc, 'A product_attachment document should have been created.');
        $this->assertSame('LM358 Datasheet', $doc->title);
        $this->assertSame('https://example.test/datasheets/lm358.pdf', $doc->source_url);
        $this->assertSame('', $doc->raw_content, 'raw_content stays empty until Python extracts the actual PDF text.');
        $meta = json_decode($doc->metadata, true);
        $this->assertSame(8001, $meta['product_id']);
        $this->assertSame('LM358 Op-Amp', $meta['product_name']);
    }

    public function test_multiple_attachments_each_get_their_own_document(): void
    {
        Bus::fake([EmbedDocumentJob::class]);
        ['schema' => $schema, 'chatbotId' => $chatbotId, 'rawKey' => $rawKey] = $this->makeTenantWithChatbot();

        $product = [
            'id' => 8002, 'name' => 'Widget', 'status' => 'publish',
            'attachments' => [
                ['url' => 'https://example.test/a.pdf', 'name' => 'A'],
                ['url' => 'https://example.test/b.pdf', 'name' => 'B'],
            ],
        ];
        $this->postJson('/api/v1/sync/products', ['chatbot_id' => $chatbotId, 'products' => [$product]], ['Authorization' => "Bearer {$rawKey}"])
            ->assertStatus(202);

        DB::statement("SET search_path TO {$schema}, public");
        $count = DB::table('documents')->where('source_type', 'product_attachment')->count();
        DB::statement('SET search_path TO public');

        $this->assertSame(2, $count);
    }

    public function test_product_without_attachments_creates_no_attachment_document(): void
    {
        Bus::fake([EmbedDocumentJob::class]);
        ['schema' => $schema, 'chatbotId' => $chatbotId, 'rawKey' => $rawKey] = $this->makeTenantWithChatbot();

        $product = ['id' => 8003, 'name' => 'No Attachments', 'status' => 'publish'];
        $this->postJson('/api/v1/sync/products', ['chatbot_id' => $chatbotId, 'products' => [$product]], ['Authorization' => "Bearer {$rawKey}"])
            ->assertStatus(202);

        Bus::assertDispatchedTimes(EmbedDocumentJob::class, 1);

        DB::statement("SET search_path TO {$schema}, public");
        $count = DB::table('documents')->where('source_type', 'product_attachment')->count();
        DB::statement('SET search_path TO public');

        $this->assertSame(0, $count);
    }

    public function test_resyncing_the_same_attachment_url_does_not_redispatch_embed_job(): void
    {
        Bus::fake([EmbedDocumentJob::class]);
        ['chatbotId' => $chatbotId, 'rawKey' => $rawKey] = $this->makeTenantWithChatbot();

        $product = [
            'id' => 8004, 'name' => 'Repeat Product', 'status' => 'publish',
            'attachments' => [['url' => 'https://example.test/repeat.pdf', 'name' => 'Repeat']],
        ];
        $this->postJson('/api/v1/sync/products', ['chatbot_id' => $chatbotId, 'products' => [$product]], ['Authorization' => "Bearer {$rawKey}"])
            ->assertStatus(202);
        $this->postJson('/api/v1/sync/products', ['chatbot_id' => $chatbotId, 'products' => [$product]], ['Authorization' => "Bearer {$rawKey}"])
            ->assertStatus(202);

        // Exactly 2 dispatches total, both from the first sync (product +
        // attachment) — the second sync's product content is byte-identical
        // (same content_hash, upsertDoc()'s unchanged-content skip applies
        // exactly like DocumentSyncSkipTest's page case), and the
        // attachment's raw_content is always '' so its hash never changes
        // either. Neither document re-dispatches EmbedDocumentJob.
        Bus::assertDispatchedTimes(EmbedDocumentJob::class, 2);
    }
}
