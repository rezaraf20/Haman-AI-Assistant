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
 * Real incident: an unfiltered sync had indexed a hamantech.ir blog post,
 * and the bot surfaced its pricing table mid-conversation to a customer who
 * never asked about it — a post_type=['page','post'] query with no way to
 * turn 'post' off on its own. sync_settings.sync_posts defaults false so
 * that stops happening for every existing and new chatbot without anyone
 * having to notice and opt out; a merchant who wants blog content answered
 * from turns it back on deliberately.
 */
class SyncSettingsFilterTest extends TestCase
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

    public function test_a_new_chatbot_defaults_to_products_and_pages_but_not_posts(): void
    {
        ['chatbotId' => $chatbotId, 'rawKey' => $rawKey] = $this->makeTenantWithChatbot();

        $r = $this->getJson("/api/v1/chatbots/{$chatbotId}/sync-settings", ['Authorization' => "Bearer {$rawKey}"]);

        $r->assertOk();
        $r->assertJsonPath('data.sync_products', true);
        $r->assertJsonPath('data.sync_pages', true);
        $r->assertJsonPath('data.sync_posts', false);
    }

    public function test_saving_sync_settings_persists_and_reads_back(): void
    {
        ['chatbotId' => $chatbotId, 'rawKey' => $rawKey] = $this->makeTenantWithChatbot();

        $this->putJson("/api/v1/chatbots/{$chatbotId}/sync-settings", [
            'sync_posts' => true,
            'excluded_category_ids' => [4, 9],
        ], ['Authorization' => "Bearer {$rawKey}"])->assertOk();

        $r = $this->getJson("/api/v1/chatbots/{$chatbotId}/sync-settings", ['Authorization' => "Bearer {$rawKey}"]);
        $r->assertJsonPath('data.sync_posts', true);
        $r->assertJsonPath('data.excluded_category_ids', [4, 9]);
        // Untouched fields keep their default, not reset to null/false.
        $r->assertJsonPath('data.sync_products', true);
        $r->assertJsonPath('data.sync_pages', true);
    }

    public function test_a_blog_post_is_not_indexed_while_sync_posts_is_off(): void
    {
        Bus::fake([EmbedDocumentJob::class]);
        ['schema' => $schema, 'chatbotId' => $chatbotId, 'rawKey' => $rawKey] = $this->makeTenantWithChatbot();

        $post = ['id' => 900, 'title' => 'Our GEO Blog Post', 'content' => 'Pricing table here.', 'url' => 'https://example.test/blog/geo', 'post_type' => 'post'];
        $page = ['id' => 901, 'title' => 'Services', 'content' => 'What we offer.', 'url' => 'https://example.test/services', 'post_type' => 'page'];

        $r = $this->postJson('/api/v1/sync/pages', ['chatbot_id' => $chatbotId, 'pages' => [$post, $page]], ['Authorization' => "Bearer {$rawKey}"]);
        $r->assertStatus(202);

        DB::statement("SET search_path TO {$schema}, public");
        $this->assertNull(DB::table('documents')->where('external_id', '900')->first(), 'The post must never have been indexed.');
        $this->assertNotNull(DB::table('documents')->where('external_id', '901')->first(), 'The page must still sync normally.');
        DB::statement('SET search_path TO public');

        Bus::assertDispatchedTimes(EmbedDocumentJob::class, 1);
    }

    public function test_a_blog_post_syncs_once_sync_posts_is_turned_on(): void
    {
        Bus::fake([EmbedDocumentJob::class]);
        ['schema' => $schema, 'chatbotId' => $chatbotId, 'rawKey' => $rawKey] = $this->makeTenantWithChatbot();

        $this->putJson("/api/v1/chatbots/{$chatbotId}/sync-settings", ['sync_posts' => true], ['Authorization' => "Bearer {$rawKey}"]);

        $post = ['id' => 902, 'title' => 'Our GEO Blog Post', 'content' => 'Pricing table here.', 'url' => 'https://example.test/blog/geo', 'post_type' => 'post'];
        $this->postJson('/api/v1/sync/pages', ['chatbot_id' => $chatbotId, 'pages' => [$post]], ['Authorization' => "Bearer {$rawKey}"])
            ->assertStatus(202);

        DB::statement("SET search_path TO {$schema}, public");
        $this->assertNotNull(DB::table('documents')->where('external_id', '902')->first());
        DB::statement('SET search_path TO public');
    }

    public function test_clear_and_reindex_removes_synced_documents_but_not_manual_faqs(): void
    {
        Bus::fake([EmbedDocumentJob::class]);
        ['schema' => $schema, 'chatbotId' => $chatbotId, 'rawKey' => $rawKey] = $this->makeTenantWithChatbot();

        $page = ['id' => 903, 'title' => 'Services', 'content' => 'What we offer.', 'url' => 'https://example.test/services', 'post_type' => 'page'];
        $this->postJson('/api/v1/sync/pages', ['chatbot_id' => $chatbotId, 'pages' => [$page]], ['Authorization' => "Bearer {$rawKey}"]);

        DB::statement("SET search_path TO {$schema}, public");
        DB::table('documents')->insert([
            'id' => (string) Str::uuid(), 'chatbot_id' => $chatbotId, 'source_type' => 'faq',
            'external_id' => 'faq_manual_1', 'title' => 'Manual FAQ', 'raw_content' => 'Q: A: ',
            'content_hash' => md5('Q: A: '), 'status' => 'indexed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $pageDocId = DB::table('documents')->where('external_id', '903')->value('id');
        DB::table('chunks')->insert([
            'id' => (string) Str::uuid(), 'document_id' => $pageDocId, 'chatbot_id' => $chatbotId,
            'chunk_index' => 0, 'content' => 'What we offer.', 'created_at' => now(),
        ]);
        DB::statement('SET search_path TO public');

        $r = $this->postJson('/api/v1/sync/clear', ['chatbot_id' => $chatbotId], ['Authorization' => "Bearer {$rawKey}"]);
        $r->assertOk();
        $r->assertJsonPath('data.documents_deleted', 1);
        $r->assertJsonPath('data.chunks_deleted', 1);

        DB::statement("SET search_path TO {$schema}, public");
        $this->assertNull(DB::table('documents')->where('external_id', '903')->first(), 'The synced page must be gone.');
        $this->assertNotNull(DB::table('documents')->where('external_id', 'faq_manual_1')->first(), 'A manually-entered FAQ must survive clearing the WordPress sync.');
        DB::statement('SET search_path TO public');
    }
}
