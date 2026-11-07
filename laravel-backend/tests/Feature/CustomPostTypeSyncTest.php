<?php
namespace Tests\Feature;

use App\Jobs\EmbedDocumentJob;
use App\Models\{ApiKey, Plan, Tenant, User};
use App\Services\TenantService;
use App\Support\SyncSettings;
use Illuminate\Support\Facades\{Bus, DB};
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Custom post types ("portfolio", "service" …) — corporate sites, not shops,
 * are half the target market, and their content lives in types the old
 * three-flag sync (products/pages/posts) could not express.
 *
 * Every test here goes through the real plugin-facing API (API-key auth,
 * request validation, SyncService) and reads back what actually landed in
 * the tenant schema — including the version-1 → version-2 settings
 * migration, run against a row stored in the old shape.
 */
class CustomPostTypeSyncTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(?int $maxDocuments = null): array
    {
        // max_documents is NOT NULL (column default 1000), so only set when a test needs a specific limit.
        $plan = Plan::create(array_filter([
            'name' => 'Test Plan', 'slug' => 'test-' . Str::random(8),
            'price_monthly' => 0, 'max_chatbots' => 1, 'max_tokens_monthly' => 1000000,
            'max_documents' => $maxDocuments, 'is_active' => true, 'sort_order' => 0,
        ], fn ($v) => $v !== null));
        $tenant = Tenant::create([
            'slug' => 'test-' . Str::random(8), 'name' => 'Agency',
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

    private function auth(string $rawKey): array
    {
        return ['Authorization' => "Bearer {$rawKey}"];
    }

    private function inSchema(string $schema, callable $fn): mixed
    {
        DB::statement("SET search_path TO {$schema}, public");
        try {
            return $fn();
        } finally {
            DB::statement('SET search_path TO public');
        }
    }

    private function portfolioItem(int $id, string $title, array $extra = []): array
    {
        return array_merge([
            'id' => $id,
            'title' => $title,
            'content' => 'طراحی و پیاده‌سازی کامل سایت فروشگاهی.',
            'excerpt' => 'یک پروژه‌ی طراحی سایت',
            'url' => "https://agency.example.test/portfolio/{$id}",
            'post_type' => 'portfolio',
            'post_type_label' => 'نمونه کار',
            'date' => '2026-03-01T10:00:00',
            'taxonomies' => [['taxonomy' => 'portfolio_cat', 'label' => 'دسته‌ی نمونه کار', 'terms' => ['طراحی سایت']]],
        ], $extra);
    }

    public function test_version_one_settings_read_as_the_same_choice(): void
    {
        $v2 = SyncSettings::normalize(['sync_products' => false, 'sync_pages' => true, 'sync_posts' => true, 'excluded_page_ids' => [5]]);

        $this->assertFalse($v2['sync_products']);
        $this->assertSame(['page', 'post'], $v2['post_types']);
        $this->assertSame([5], $v2['excluded_page_ids']);
        $this->assertSame(2, $v2['version']);
    }

    public function test_the_schema_default_is_exactly_the_version_two_defaults(): void
    {
        ['schema' => $schema, 'chatbotId' => $chatbotId] = $this->makeTenant();

        $stored = $this->inSchema($schema, fn () => DB::table('chatbots')->where('id', $chatbotId)->value('sync_settings'));

        $this->assertEquals(SyncSettings::DEFAULTS, SyncSettings::normalize(json_decode($stored, true)));
        $this->assertSame(['page'], json_decode($stored, true)['post_types'], 'The literal column default itself must already be version 2.');
    }

    public function test_the_migration_rewrites_a_stored_version_one_row_without_changing_its_meaning(): void
    {
        ['schema' => $schema, 'chatbotId' => $chatbotId] = $this->makeTenant();
        $v1 = ['sync_products' => false, 'sync_pages' => false, 'sync_posts' => true, 'excluded_category_ids' => [4, 9], 'excluded_page_ids' => []];
        $this->inSchema($schema, fn () => DB::table('chatbots')->where('id', $chatbotId)->update(['sync_settings' => json_encode($v1)]));

        $migration = require base_path('database/migrations/2026_11_07_000001_convert_chatbot_sync_settings_to_post_type_list.php');
        $migration->up();

        $after = json_decode($this->inSchema($schema, fn () => DB::table('chatbots')->where('id', $chatbotId)->value('sync_settings')), true);
        $this->assertSame(2, $after['version']);
        $this->assertFalse($after['sync_products']);
        $this->assertSame(['post'], $after['post_types']);
        $this->assertSame([4, 9], $after['excluded_category_ids']);
        $this->assertArrayNotHasKey('sync_pages', $after);
    }

    public function test_a_legacy_plugin_put_toggles_pages_without_wiping_a_custom_type(): void
    {
        ['chatbotId' => $chatbotId, 'rawKey' => $rawKey] = $this->makeTenant();

        $this->putJson("/api/v1/chatbots/{$chatbotId}/sync-settings", ['post_types' => ['page', 'portfolio']], $this->auth($rawKey))->assertOk();
        // What every plugin before 2.6.0 sends on save.
        $this->putJson("/api/v1/chatbots/{$chatbotId}/sync-settings", ['sync_pages' => false, 'sync_posts' => true], $this->auth($rawKey))->assertOk();

        $r = $this->getJson("/api/v1/chatbots/{$chatbotId}/sync-settings", $this->auth($rawKey))->assertOk();
        $this->assertEqualsCanonicalizing(['portfolio', 'post'], $r->json('data.post_types'));
        $r->assertJsonPath('data.sync_pages', false);
        $r->assertJsonPath('data.sync_posts', true);
    }

    public function test_a_custom_post_type_is_indexed_with_its_taxonomy_type_label_and_link(): void
    {
        Bus::fake([EmbedDocumentJob::class]);
        ['schema' => $schema, 'chatbotId' => $chatbotId, 'rawKey' => $rawKey] = $this->makeTenant();
        $this->putJson("/api/v1/chatbots/{$chatbotId}/sync-settings", ['post_types' => ['page', 'portfolio']], $this->auth($rawKey))->assertOk();

        $this->postJson('/api/v1/sync/pages', ['chatbot_id' => $chatbotId, 'pages' => [$this->portfolioItem(301, 'سایت فروشگاه کفش')]], $this->auth($rawKey))
            ->assertStatus(202)
            ->assertJsonPath('data.result.new', 1);

        $doc = $this->inSchema($schema, fn () => DB::table('documents')->where('external_id', '301')->first());
        $this->assertSame('wordpress_cpt', $doc->source_type);
        $meta = json_decode($doc->metadata, true);
        $this->assertSame('portfolio', $meta['post_type']);
        $this->assertSame('نمونه کار', $meta['post_type_label']);
        $this->assertSame('https://agency.example.test/portfolio/301', $meta['url']);
        $this->assertSame(['طراحی سایت'], $meta['terms']);
        // In the indexed TEXT, not only metadata — that's what retrieval searches.
        $this->assertStringContainsString('دسته‌ی نمونه کار: طراحی سایت', $doc->raw_content);
        $this->assertStringContainsString('Type: نمونه کار', $doc->raw_content);
        $this->assertStringContainsString('Link: https://agency.example.test/portfolio/301', $doc->raw_content);
        $this->assertStringContainsString('Date: 2026-03-01', $doc->raw_content);
        Bus::assertDispatchedTimes(EmbedDocumentJob::class, 1);
    }

    public function test_a_custom_post_type_that_is_not_selected_is_never_stored(): void
    {
        Bus::fake([EmbedDocumentJob::class]);
        ['schema' => $schema, 'chatbotId' => $chatbotId, 'rawKey' => $rawKey] = $this->makeTenant();

        $this->postJson('/api/v1/sync/pages', ['chatbot_id' => $chatbotId, 'pages' => [$this->portfolioItem(302, 'X')]], $this->auth($rawKey))
            ->assertStatus(202)
            ->assertJsonPath('data.result.skipped', 1);

        $this->assertNull($this->inSchema($schema, fn () => DB::table('documents')->where('external_id', '302')->first()));
        Bus::assertNotDispatched(EmbedDocumentJob::class);
    }

    public function test_only_approved_custom_fields_reach_the_index_and_underscore_keys_never_do(): void
    {
        Bus::fake([EmbedDocumentJob::class]);
        ['schema' => $schema, 'chatbotId' => $chatbotId, 'rawKey' => $rawKey] = $this->makeTenant();
        $this->putJson("/api/v1/chatbots/{$chatbotId}/sync-settings", [
            'post_types' => ['portfolio'],
            // The owner approved client_name only; _internal_note is refused
            // even if something tries to approve it.
            'custom_fields' => ['portfolio' => ['client_name', '_internal_note']],
        ], $this->auth($rawKey))->assertOk()->assertJsonPath('data.custom_fields.portfolio', ['client_name']);

        $item = $this->portfolioItem(303, 'Y', ['custom_fields' => [
            'client_name' => 'شرکت نمونه',
            'wholesale_price' => '1200000',
            '_internal_note' => 'do not show',
        ]]);
        $this->postJson('/api/v1/sync/pages', ['chatbot_id' => $chatbotId, 'pages' => [$item]], $this->auth($rawKey))->assertStatus(202);

        $raw = $this->inSchema($schema, fn () => DB::table('documents')->where('external_id', '303')->value('raw_content'));
        $this->assertStringContainsString('client_name: شرکت نمونه', $raw);
        $this->assertStringNotContainsString('1200000', $raw);
        $this->assertStringNotContainsString('do not show', $raw);
    }

    public function test_removing_an_approved_field_is_honoured_on_the_next_sync(): void
    {
        Bus::fake([EmbedDocumentJob::class]);
        ['schema' => $schema, 'chatbotId' => $chatbotId, 'rawKey' => $rawKey] = $this->makeTenant();
        $this->putJson("/api/v1/chatbots/{$chatbotId}/sync-settings", ['post_types' => ['portfolio'], 'custom_fields' => ['portfolio' => ['client_name', 'project_year']]], $this->auth($rawKey))->assertOk();
        $item = $this->portfolioItem(304, 'Z', ['custom_fields' => ['client_name' => 'Acme', 'project_year' => '1403']]);
        $this->postJson('/api/v1/sync/pages', ['chatbot_id' => $chatbotId, 'pages' => [$item]], $this->auth($rawKey))->assertStatus(202);

        $this->putJson("/api/v1/chatbots/{$chatbotId}/sync-settings", ['custom_fields' => ['portfolio' => ['project_year']]], $this->auth($rawKey))->assertOk();
        $this->postJson('/api/v1/sync/pages', ['chatbot_id' => $chatbotId, 'pages' => [$item]], $this->auth($rawKey))
            ->assertStatus(202)
            ->assertJsonPath('data.result.updated', 1);

        $raw = $this->inSchema($schema, fn () => DB::table('documents')->where('external_id', '304')->value('raw_content'));
        $this->assertStringNotContainsString('Acme', $raw);
        $this->assertStringContainsString('project_year: 1403', $raw);
    }

    public function test_turning_a_post_type_off_archives_its_documents_and_drops_their_chunks(): void
    {
        Bus::fake([EmbedDocumentJob::class]);
        ['schema' => $schema, 'chatbotId' => $chatbotId, 'rawKey' => $rawKey] = $this->makeTenant();
        $this->putJson("/api/v1/chatbots/{$chatbotId}/sync-settings", ['post_types' => ['page', 'portfolio']], $this->auth($rawKey))->assertOk();
        $this->postJson('/api/v1/sync/pages', ['chatbot_id' => $chatbotId, 'pages' => [
            $this->portfolioItem(305, 'A'),
            ['id' => 306, 'title' => 'About', 'content' => 'About us.', 'url' => 'https://agency.example.test/about', 'post_type' => 'page'],
        ]], $this->auth($rawKey))->assertStatus(202);

        // Chunks as embed.py would have written them for the portfolio item.
        $this->inSchema($schema, function () use ($chatbotId) {
            $docId = DB::table('documents')->where('external_id', '305')->value('id');
            DB::table('chunks')->insert(['id' => (string) Str::uuid(), 'document_id' => $docId, 'chatbot_id' => $chatbotId, 'chunk_index' => 0, 'content' => 'x', 'metadata' => '{}', 'created_at' => now()]);
        });

        $this->putJson("/api/v1/chatbots/{$chatbotId}/sync-settings", ['post_types' => ['page']], $this->auth($rawKey))
            ->assertOk()
            ->assertJsonPath('data.archived_documents', 1);

        $this->inSchema($schema, function () {
            $portfolio = DB::table('documents')->where('external_id', '305')->first();
            $this->assertSame('archived', $portfolio->status);
            $this->assertSame(0, DB::table('chunks')->where('document_id', $portfolio->id)->count());
            $this->assertNotSame('archived', DB::table('documents')->where('external_id', '306')->value('status'), 'Pages stay untouched.');
        });
    }

    public function test_the_plan_document_limit_counts_every_kind_and_reports_what_it_refused(): void
    {
        Bus::fake([EmbedDocumentJob::class]);
        ['schema' => $schema, 'chatbotId' => $chatbotId, 'rawKey' => $rawKey] = $this->makeTenant(maxDocuments: 3);
        $this->putJson("/api/v1/chatbots/{$chatbotId}/sync-settings", ['post_types' => ['page', 'portfolio']], $this->auth($rawKey))->assertOk();

        // One product already counts toward the same limit.
        $this->postJson('/api/v1/sync/products', ['chatbot_id' => $chatbotId, 'products' => [['id' => 1, 'name' => 'Shoe', 'price' => '10']]], $this->auth($rawKey))
            ->assertStatus(202);

        $this->postJson('/api/v1/sync/pages', ['chatbot_id' => $chatbotId, 'pages' => [
            $this->portfolioItem(401, 'P1'), $this->portfolioItem(402, 'P2'), $this->portfolioItem(403, 'P3'),
        ]], $this->auth($rawKey))
            ->assertStatus(202)
            ->assertJsonPath('data.result.new', 2)
            ->assertJsonPath('data.result.over_limit', 1);

        $this->assertSame(3, $this->inSchema($schema, fn () => DB::table('documents')->where('status', '!=', 'archived')->count()));

        // At the limit, an item already indexed can still be updated.
        $this->postJson('/api/v1/sync/pages', ['chatbot_id' => $chatbotId, 'pages' => [$this->portfolioItem(401, 'P1 renamed')]], $this->auth($rawKey))
            ->assertStatus(202)
            ->assertJsonPath('data.result.updated', 1)
            ->assertJsonPath('data.result.over_limit', 0);
    }

    public function test_a_deleted_custom_post_is_archived_by_the_webhook(): void
    {
        Bus::fake([EmbedDocumentJob::class]);
        ['schema' => $schema, 'chatbotId' => $chatbotId, 'rawKey' => $rawKey, 'tenant' => $tenant] = $this->makeTenant();
        $this->putJson("/api/v1/chatbots/{$chatbotId}/sync-settings", ['post_types' => ['portfolio']], $this->auth($rawKey))->assertOk();
        $this->postJson('/api/v1/sync/pages', ['chatbot_id' => $chatbotId, 'pages' => [$this->portfolioItem(501, 'Gone soon')]], $this->auth($rawKey))->assertStatus(202);

        $this->inSchema($schema, fn () => app(\App\Services\SyncService::class)->processWebhook(
            ['event' => 'page.deleted', 'chatbot_id' => $chatbotId, 'data' => ['id' => 501]], $schema
        ));

        $this->assertSame('archived', $this->inSchema($schema, fn () => DB::table('documents')->where('external_id', '501')->value('status')));
    }

    public function test_the_knowledge_page_breaks_documents_down_by_post_type(): void
    {
        Bus::fake([EmbedDocumentJob::class]);
        ['schema' => $schema, 'chatbotId' => $chatbotId, 'rawKey' => $rawKey, 'tenant' => $tenant] = $this->makeTenant(maxDocuments: 1);
        $this->putJson("/api/v1/chatbots/{$chatbotId}/sync-settings", ['post_types' => ['page', 'portfolio']], $this->auth($rawKey))->assertOk();
        $this->postJson('/api/v1/sync/pages', ['chatbot_id' => $chatbotId, 'pages' => [
            $this->portfolioItem(601, 'P'), $this->portfolioItem(602, 'Q'),
        ]], $this->auth($rawKey))->assertStatus(202);

        $user = User::create([
            'tenant_id' => $tenant->id, 'email' => Str::random(10) . '@example.test',
            'password' => bcrypt('x'), 'password_hash' => bcrypt('x'), 'name' => 'Owner', 'role' => 'owner',
            'email_verified_at' => now(), 'locale' => 'en',
        ]);

        $this->actingAs($user, 'web')->get('/portal/knowledge')
            ->assertOk()
            ->assertSee('نمونه کار')
            ->assertSee(__('portal.knowledge_over_limit_title', ['count' => '1'], 'en'))
            ->assertSee('1 of 1 documents');
    }
}
