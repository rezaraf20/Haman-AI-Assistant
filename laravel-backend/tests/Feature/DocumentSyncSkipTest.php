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
 * Guards the fix for SyncService::upsertDoc() always forcing status='pending'
 * regardless of whether content actually changed — every sync, even a
 * no-op re-sync of unchanged content, was re-embedding everything (one
 * external embedding-API call per chunk), which is pure wasted cost once
 * ProfitMargin.php makes that cost visible per tenant. Also guards the new
 * real-time deletion path (product.deleted / page.deleted webhooks).
 */
class DocumentSyncSkipTest extends TestCase
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
            // Needed for the webhook-signature test below — Tenant::
            // getWebhookSecret() reads this; without it both sides compute
            // against a null secret and VerifyWebhookSignature always 401s.
            'settings' => ['webhook_secret' => Str::random(32)],
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

    public function test_resyncing_unchanged_page_skips_embedding_and_only_touches_last_synced_at(): void
    {
        Bus::fake([EmbedDocumentJob::class]);
        ['schema' => $schema, 'chatbotId' => $chatbotId, 'rawKey' => $rawKey] = $this->makeTenantWithChatbot();

        $page = ['id' => 501, 'title' => 'About Us', 'content' => 'We build things.', 'url' => 'https://example.test/about', 'post_type' => 'page'];

        $r1 = $this->postJson('/api/v1/sync/pages', ['chatbot_id' => $chatbotId, 'pages' => [$page]], ['Authorization' => "Bearer {$rawKey}"]);
        $r1->assertStatus(202);
        Bus::assertDispatchedTimes(EmbedDocumentJob::class, 1);

        DB::statement("SET search_path TO {$schema}, public");
        $firstSyncedAt = DB::table('documents')->where('external_id', '501')->value('last_synced_at');
        DB::statement('SET search_path TO public');

        sleep(1); // last_synced_at must actually move forward to prove the skip path still ran an UPDATE

        $r2 = $this->postJson('/api/v1/sync/pages', ['chatbot_id' => $chatbotId, 'pages' => [$page]], ['Authorization' => "Bearer {$rawKey}"]);
        $r2->assertStatus(202);
        // Still exactly 1 total — the second sync must NOT have dispatched a second embed job.
        Bus::assertDispatchedTimes(EmbedDocumentJob::class, 1);

        DB::statement("SET search_path TO {$schema}, public");
        $doc = DB::table('documents')->where('external_id', '501')->first();
        // latest('id') would be wrong here: sync_jobs.id is a UUID (HasUuid),
        // and ordering a UUID string lexicographically has nothing to do
        // with insertion order — created_at is the real chronological signal.
        $secondJobResult = DB::table('sync_jobs')->where('job_type', 'pages')->latest('created_at')->first();
        DB::statement('SET search_path TO public');

        $this->assertNotEquals($firstSyncedAt, $doc->last_synced_at, 'last_synced_at should still be updated on a skipped re-sync.');
        $result = json_decode($secondJobResult->result, true);
        $this->assertSame(0, $result['new']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(1, $result['skipped']);
    }

    public function test_changed_content_is_re_embedded_not_skipped(): void
    {
        Bus::fake([EmbedDocumentJob::class]);
        ['chatbotId' => $chatbotId, 'rawKey' => $rawKey] = $this->makeTenantWithChatbot();

        $page = ['id' => 502, 'title' => 'Pricing', 'content' => 'Plan A costs $10.', 'url' => 'https://example.test/pricing', 'post_type' => 'page'];
        $this->postJson('/api/v1/sync/pages', ['chatbot_id' => $chatbotId, 'pages' => [$page]], ['Authorization' => "Bearer {$rawKey}"])->assertStatus(202);

        $page['content'] = 'Plan A now costs $15.';
        $this->postJson('/api/v1/sync/pages', ['chatbot_id' => $chatbotId, 'pages' => [$page]], ['Authorization' => "Bearer {$rawKey}"])->assertStatus(202);

        Bus::assertDispatchedTimes(EmbedDocumentJob::class, 2);
    }

    public function test_product_deleted_webhook_archives_document_and_removes_chunks(): void
    {
        Bus::fake([EmbedDocumentJob::class]);
        ['tenant' => $tenant, 'schema' => $schema, 'chatbotId' => $chatbotId, 'rawKey' => $rawKey] = $this->makeTenantWithChatbot();

        $product = ['id' => 9001, 'name' => 'Widget', 'sku' => 'W-1', 'price' => 100, 'status' => 'publish'];
        $this->postJson('/api/v1/sync/products', ['chatbot_id' => $chatbotId, 'products' => [$product]], ['Authorization' => "Bearer {$rawKey}"])->assertStatus(202);

        DB::statement("SET search_path TO {$schema}, public");
        $docId = DB::table('documents')->where('external_id', '9001')->value('id');
        DB::table('chunks')->insert([
            'id' => (string) Str::uuid(), 'document_id' => $docId, 'chatbot_id' => $chatbotId,
            'chunk_index' => 0, 'content' => 'Widget: $100', 'created_at' => now(),
        ]);
        DB::statement('SET search_path TO public');

        $secret = $tenant->getWebhookSecret();
        $payload = ['event' => 'product.deleted', 'chatbot_id' => $chatbotId, 'data' => ['id' => 9001]];
        $body = json_encode($payload);
        $sig = 'sha256=' . hash_hmac('sha256', $body, $secret);

        $response = $this->call('POST', '/api/v1/sync/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => "Bearer {$rawKey}",
            'HTTP_X_HAMMAN_SIGNATURE' => $sig,
        ], $body);
        $response->assertStatus(200);

        DB::statement("SET search_path TO {$schema}, public");
        $doc = DB::table('documents')->where('external_id', '9001')->first();
        $chunkCount = DB::table('chunks')->where('document_id', $docId)->count();
        $productExists = DB::table('products')->where('woo_product_id', 9001)->exists();
        DB::statement('SET search_path TO public');

        $this->assertSame('archived', $doc->status);
        $this->assertSame(0, $chunkCount, 'Chunks for a deleted product should be removed, not left orphaned.');
        $this->assertFalse($productExists, 'The products table row should be removed when the product is deleted at the source.');
    }
}
