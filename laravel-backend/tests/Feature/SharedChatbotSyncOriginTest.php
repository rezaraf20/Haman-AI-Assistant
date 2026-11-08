<?php
namespace Tests\Feature;

use App\Jobs\EmbedDocumentJob;
use App\Models\{ApiKey, Plan, Tenant, User};
use App\Services\TenantService;
use Illuminate\Support\Facades\{Bus, DB};
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The live incident: hamantech.ir and khonehrangi.ir were connected to one
 * chatbot, and whichever saved its plugin settings last archived the other
 * site's documents. Only the chatbot's registered domain may now archive,
 * delete or overwrite sync settings (App\Support\SyncOrigin); every other
 * site can still add and update content, and each refusal is recorded for
 * the platform admin and shown to the owner in the portal.
 *
 * Each request is made the way WordPress itself makes it: the site's own
 * address in WordPress's default User-Agent ("WordPress/<ver>; <home url>").
 */
class SharedChatbotSyncOriginTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER = 'hamantech.ir';
    private const OTHER = 'khonehrangi.ir';

    private function makeTenant(?string $registeredDomain = self::OWNER): array
    {
        $plan = Plan::create([
            'name' => 'Test Plan', 'slug' => 'test-' . Str::random(8),
            'price_monthly' => 0, 'max_chatbots' => 1, 'max_tokens_monthly' => 1000000,
            'is_active' => true, 'sort_order' => 0,
        ]);
        $tenant = Tenant::create([
            'slug' => 'test-' . Str::random(8), 'name' => 'Haman Tech',
            'email' => Str::random(12) . '@example.test', 'plan_id' => $plan->id,
            'schema_name' => 'placeholder', 'status' => 'active', 'trial_ends_at' => now()->addDays(14),
            'settings' => ['webhook_secret' => Str::random(32)],
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
            'is_active' => true, 'name' => 'Bot', 'primary_domain' => $registeredDomain,
        ]);

        $rawKey = 'hfp_' . Str::random(32);
        ApiKey::create([
            'tenant_id' => $tenant->id, 'chatbot_id' => $chatbotId, 'name' => 'Shared Key',
            'key_prefix' => substr($rawKey, 0, 12),
            'key_hash' => password_hash($rawKey, PASSWORD_BCRYPT, ['cost' => 4]),
            'scopes' => ['read', 'write', 'sync'],
        ]);

        return compact('tenant', 'schema', 'chatbotId', 'rawKey');
    }

    /** Headers exactly as wp_remote_request() sends them from $site. */
    private function asSite(string $rawKey, ?string $site): array
    {
        $h = ['Authorization' => "Bearer {$rawKey}"];
        if ($site !== null) $h['User-Agent'] = "WordPress/7.1.4; https://{$site}";
        return $h;
    }

    private function page(int $id, string $type, string $site): array
    {
        return ['id' => $id, 'title' => "{$type} {$id}", 'content' => "Content of {$type} {$id} on {$site}.", 'url' => "https://{$site}/?p={$id}", 'post_type' => $type, 'post_type_label' => ucfirst($type)];
    }

    private function inSchema(string $schema, callable $fn): mixed
    {
        DB::statement("SET search_path TO {$schema}, public");
        try { return $fn(); } finally { DB::statement('SET search_path TO public'); }
    }

    private function liveCount(string $schema): int
    {
        return $this->inSchema($schema, fn () => DB::table('documents')->where('status', '!=', 'archived')->count());
    }

    private function signedWebhook(Tenant $tenant, string $rawKey, ?string $site, array $payload)
    {
        $body = json_encode($payload);
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => "Bearer {$rawKey}",
            'HTTP_X_HAMAN_SIGNATURE' => 'sha256=' . hash_hmac('sha256', $body, $tenant->fresh()->getWebhookSecret()),
        ];
        if ($site !== null) $server['HTTP_USER_AGENT'] = "WordPress/7.1.4; https://{$site}";
        return $this->call('POST', '/api/v1/sync/webhook', [], [], [], $server, $body);
    }

    public function test_two_sites_syncing_one_chatbot_archive_nothing_and_the_refusal_is_recorded(): void
    {
        Bus::fake([EmbedDocumentJob::class]);
        ['tenant' => $tenant, 'schema' => $schema, 'chatbotId' => $bot, 'rawKey' => $key] = $this->makeTenant();

        // 1. The owner's site (hamantech.ir) has pages and a portfolio, and syncs them.
        $this->putJson("/api/v1/chatbots/{$bot}/sync-settings", ['post_types' => ['page', 'portfolio']], $this->asSite($key, self::OWNER))->assertOk();
        $this->postJson('/api/v1/sync/pages', ['chatbot_id' => $bot, 'pages' => [
            $this->page(11, 'page', self::OWNER), $this->page(12, 'portfolio', self::OWNER), $this->page(13, 'portfolio', self::OWNER),
        ]], $this->asSite($key, self::OWNER))->assertStatus(202)->assertJsonPath('data.result.new', 3);
        $this->assertSame(3, $this->liveCount($schema));

        // 2. The second site (khonehrangi.ir) has no portfolio type. Its settings
        //    save is exactly what archived hamantech.ir's portfolio before.
        $r = $this->putJson("/api/v1/chatbots/{$bot}/sync-settings", ['post_types' => ['page']], $this->asSite($key, self::OTHER));
        $r->assertStatus(409)
            ->assertJsonPath('reason', 'domain_mismatch')
            ->assertJsonPath('origin_domain', self::OTHER)
            ->assertJsonPath('registered_domain', self::OWNER);
        $this->assertStringContainsString(self::OTHER, $r->json('error'));
        $this->assertStringContainsString(self::OWNER, $r->json('error'));

        // Adding and updating from the second site still works.
        $this->postJson('/api/v1/sync/pages', ['chatbot_id' => $bot, 'pages' => [$this->page(21, 'page', self::OTHER)]], $this->asSite($key, self::OTHER))
            ->assertStatus(202)->assertJsonPath('data.result.new', 1);

        // Nothing was archived, and the settings are still the owner's.
        $this->assertSame(4, $this->liveCount($schema));
        $this->assertSame(0, $this->inSchema($schema, fn () => DB::table('documents')->where('status', 'archived')->count()));
        $this->assertEqualsCanonicalizing(['page', 'portfolio'],
            $this->getJson("/api/v1/chatbots/{$bot}/sync-settings", $this->asSite($key, self::OTHER))->json('data.post_types'));

        // 3. The alert: one row for the chatbot naming both domains, and the
        //    platform activity log entry an admin sees.
        $alert = DB::table('sync_domain_alerts')->where('chatbot_id', $bot)->first();
        $this->assertNotNull($alert);
        $this->assertSame(self::OTHER, $alert->origin_domain);
        $this->assertSame(self::OWNER, $alert->registered_domain);
        $this->assertSame('settings', $alert->action);
        $this->assertSame($tenant->id, $alert->tenant_id);
        $log = DB::table('platform_activity_log')->where('action', 'sync_domain_mismatch')->where('subject_id', $bot)->first();
        $this->assertNotNull($log, 'the platform admin must see the refusal');
        $this->assertSame('system', $log->user_email);
        $this->assertEqualsCanonicalizing(['origin_domain' => self::OTHER, 'registered_domain' => self::OWNER, 'refused' => 'settings'], json_decode($log->after, true));

        // A retry from the same site bumps the count instead of adding rows.
        $this->putJson("/api/v1/chatbots/{$bot}/sync-settings", ['post_types' => ['page']], $this->asSite($key, self::OTHER))->assertStatus(409);
        $this->assertSame(1, DB::table('sync_domain_alerts')->where('chatbot_id', $bot)->count());
        $this->assertSame(2, (int) DB::table('sync_domain_alerts')->where('chatbot_id', $bot)->value('occurrences'));
        $this->assertSame(1, DB::table('platform_activity_log')->where('action', 'sync_domain_mismatch')->count(), 'no log flood from retries');
    }

    public function test_the_other_site_cannot_clear_the_index_or_delete_a_document_with_the_same_id(): void
    {
        Bus::fake([EmbedDocumentJob::class]);
        ['tenant' => $tenant, 'schema' => $schema, 'chatbotId' => $bot, 'rawKey' => $key] = $this->makeTenant();
        $this->putJson("/api/v1/chatbots/{$bot}/sync-settings", ['post_types' => ['page']], $this->asSite($key, self::OWNER))->assertOk();
        $this->postJson('/api/v1/sync/pages', ['chatbot_id' => $bot, 'pages' => [$this->page(12, 'page', self::OWNER)]], $this->asSite($key, self::OWNER))->assertStatus(202);

        // "Clear and reindex" pressed on the other site: a hard delete of everything.
        $this->postJson('/api/v1/sync/clear', ['chatbot_id' => $bot], $this->asSite($key, self::OTHER))
            ->assertStatus(409)->assertJsonPath('reason', 'domain_mismatch');
        $this->assertSame(1, $this->liveCount($schema));

        // The other site trashes ITS post 12 — same WordPress ID as the owner's page.
        $this->signedWebhook($tenant, $key, self::OTHER, ['event' => 'page.deleted', 'chatbot_id' => $bot, 'data' => ['id' => 12]])
            ->assertOk()->assertJsonPath('data.skipped', 'domain_mismatch');
        $this->assertSame(1, $this->liveCount($schema), "the owner's page 12 must survive");

        $this->assertEqualsCanonicalizing(['clear', 'delete'],
            DB::table('sync_domain_alerts')->where('chatbot_id', $bot)->pluck('action')->all());

        // The owner's own site can still do all of it.
        $this->signedWebhook($tenant, $key, self::OWNER, ['event' => 'page.deleted', 'chatbot_id' => $bot, 'data' => ['id' => 12]])
            ->assertOk()->assertJsonMissingPath('data.skipped');
        $this->assertSame(0, $this->liveCount($schema));
    }

    public function test_an_unidentified_site_or_a_chatbot_without_a_domain_never_archives(): void
    {
        Bus::fake([EmbedDocumentJob::class]);

        // A request that does not say where it came from.
        ['chatbotId' => $bot, 'rawKey' => $key] = $this->makeTenant();
        $this->putJson("/api/v1/chatbots/{$bot}/sync-settings", ['post_types' => ['page']], $this->asSite($key, null))
            ->assertStatus(409)->assertJsonPath('origin_domain', null);
        $this->assertSame('', DB::table('sync_domain_alerts')->where('chatbot_id', $bot)->value('origin_domain'));

        // A chatbot with no registered domain: there is nothing to compare
        // against, so no site may archive for it.
        ['chatbotId' => $bot2, 'rawKey' => $key2] = $this->makeTenant(registeredDomain: null);
        $this->putJson("/api/v1/chatbots/{$bot2}/sync-settings", ['post_types' => ['page']], $this->asSite($key2, self::OWNER))
            ->assertStatus(409)->assertJsonPath('registered_domain', null);

        // The X-Haman-Site header and www./case differences are understood.
        ['chatbotId' => $bot3, 'rawKey' => $key3] = $this->makeTenant();
        $this->putJson("/api/v1/chatbots/{$bot3}/sync-settings", ['post_types' => ['page']],
            ['Authorization' => "Bearer {$key3}", 'X-Haman-Site' => 'https://WWW.HamanTech.ir/'])->assertOk();
    }

    public function test_the_owner_sees_the_refusal_on_the_knowledge_page(): void
    {
        Bus::fake([EmbedDocumentJob::class]);
        ['tenant' => $tenant, 'chatbotId' => $bot, 'rawKey' => $key] = $this->makeTenant();
        $this->putJson("/api/v1/chatbots/{$bot}/sync-settings", ['post_types' => ['page']], $this->asSite($key, self::OTHER))->assertStatus(409);

        $user = User::create([
            'tenant_id' => $tenant->id, 'email' => Str::random(10) . '@example.test',
            'password' => bcrypt('x'), 'password_hash' => bcrypt('x'), 'name' => 'Owner', 'role' => 'owner',
            'email_verified_at' => now(), 'locale' => 'en',
        ]);

        $this->actingAs($user, 'web')->get("/portal/knowledge?chatbot={$bot}")
            ->assertOk()
            ->assertSee(__('sync_origin.portal_title'))
            ->assertSee(self::OTHER)
            ->assertSee(self::OWNER)
            ->assertSee(__('sync_origin.action_settings'));
    }
}
