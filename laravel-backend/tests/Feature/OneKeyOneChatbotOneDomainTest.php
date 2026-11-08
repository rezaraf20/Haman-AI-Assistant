<?php
namespace Tests\Feature;

use App\Filament\Customer\Pages\MyChatbots;
use App\Models\{ApiKey, ChatbotIndexEntry, Plan, Tenant, User};
use App\Services\TenantService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * One API key = one chatbot = one domain.
 *
 * The missing constraint behind three problems at once: a chatbot with no
 * domain answered every site; two sites sharing a chatbot overwrote each
 * other's sync settings; and one site's usage landed on another's account.
 * A key not bound to a chatbot — or bound to one but sent with another's
 * id — could act on every chatbot of its tenant.
 */
class OneKeyOneChatbotOneDomainTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(int $chatbots = 1, ?string $domain = 'shop.example.test', bool $bindKey = true): array
    {
        $plan = Plan::create([
            'name' => 'Test Plan', 'slug' => 'test-' . Str::random(8),
            'price_monthly' => 0, 'max_chatbots' => 5, 'max_tokens_monthly' => 1000000,
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

        $ids = [];
        for ($i = 0; $i < $chatbots; $i++) {
            $id = (string) Str::uuid();
            DB::statement("SET search_path TO {$schema}, public");
            DB::table('chatbots')->insert([
                'id' => $id, 'name' => "Bot {$i}", 'type' => 'support', 'status' => 'active',
                'is_active' => true, 'language' => 'fa', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::statement('SET search_path TO public');
            DB::table('chatbot_index')->insert([
                'chatbot_id' => $id, 'tenant_id' => $tenant->id, 'schema_name' => $schema,
                'is_active' => true, 'name' => "Bot {$i}",
                'primary_domain' => $domain === null ? null : ($i === 0 ? $domain : "site{$i}.example.test"),
            ]);
            $ids[] = $id;
        }

        $raw = 'hfp_' . Str::random(32);
        $key = ApiKey::create([
            'tenant_id' => $tenant->id, 'chatbot_id' => $bindKey ? $ids[0] : null, 'name' => 'WordPress Plugin',
            'key_prefix' => substr($raw, 0, 12),
            'key_hash' => password_hash($raw, PASSWORD_BCRYPT, ['cost' => 4]),
            'scopes' => ['read', 'write', 'sync'],
        ]);

        return ['tenant' => $tenant, 'schema' => $schema, 'chatbotIds' => $ids, 'raw' => $raw, 'key' => $key];
    }

    private function bearer(string $raw): array
    {
        return ['Authorization' => "Bearer {$raw}", 'User-Agent' => 'WordPress/7.1.4; https://shop.example.test'];
    }

    // ── One key = one chatbot ─────────────────────────────────────────

    public function test_a_key_cannot_act_on_another_chatbot_of_the_same_tenant(): void
    {
        ['chatbotIds' => [$own, $other], 'raw' => $raw] = $this->makeTenant(chatbots: 2);

        // By route id …
        $this->getJson("/api/v1/chatbots/{$other}/knowledge", $this->bearer($raw))
            ->assertForbidden()->assertJsonPath('reason', 'api_key_other_chatbot');
        // … and by body chatbot_id.
        $this->postJson('/api/v1/sync/pages', ['chatbot_id' => $other, 'pages' => [['id' => 1, 'title' => 'x', 'post_type' => 'page']]], $this->bearer($raw))
            ->assertForbidden()->assertJsonPath('reason', 'api_key_other_chatbot');

        // Its own chatbot works, and listing shows only that one.
        $this->getJson("/api/v1/chatbots/{$own}/knowledge", $this->bearer($raw))->assertOk();
        $this->assertSame([$own], collect($this->getJson('/api/v1/chatbots', $this->bearer($raw))->assertOk()->json('data'))->pluck('id')->all());
    }

    public function test_an_unbound_key_is_refused_with_a_sentence_the_owner_can_act_on(): void
    {
        ['chatbotIds' => [$bot], 'raw' => $raw] = $this->makeTenant(bindKey: false);

        $r = $this->getJson("/api/v1/chatbots/{$bot}/knowledge", $this->bearer($raw))
            ->assertForbidden()->assertJsonPath('reason', 'api_key_unbound');
        $this->assertStringContainsString(__('chatbot_domain.api_key_unbound', [], 'en'), $r->json('error'));

        $this->postJson('/api/v1/connection-test', [], ['Authorization' => "Bearer {$raw}"])
            ->assertForbidden()->assertJsonPath('reason', 'key_unbound');
    }

    // ── One chatbot = one domain ──────────────────────────────────────

    public function test_a_request_from_an_unregistered_domain_is_refused_recorded_and_shown_to_the_owner(): void
    {
        ['tenant' => $tenant, 'chatbotIds' => [$bot]] = $this->makeTenant();

        $r = $this->postJson('/api/v1/chat/session', ['chatbot_id' => $bot, 'session_id' => 's1'], ['Origin' => 'https://khonehrangi.example.test']);
        $r->assertForbidden()->assertJsonPath('reason', 'domain_not_registered');
        $this->assertStringContainsString(__('chatbot_domain.domain_not_registered', [], 'en'), $r->json('message'));
        $this->assertStringNotContainsString('shop.example.test', $r->json('message'), 'the registered domain is not revealed to whoever asks');

        $this->postJson('/api/v1/chat/session', ['chatbot_id' => $bot, 'session_id' => 's2'], ['Origin' => 'https://khonehrangi.example.test'])->assertForbidden();

        $row = DB::table('domain_rejections')->where('chatbot_id', $bot)->first();
        $this->assertSame('khonehrangi.example.test', $row->origin_domain);
        $this->assertSame(2, (int) $row->occurrences);
        $this->assertSame(1, DB::table('platform_activity_log')->where('action', 'widget_domain_rejected')->where('subject_id', $bot)->count());

        // The registered domain still works.
        $this->postJson('/api/v1/chat/session', ['chatbot_id' => $bot, 'session_id' => 's3'], ['Origin' => 'https://www.shop.example.test'])->assertStatus(201);

        // The owner sees it on My Chatbots.
        $user = User::create([
            'tenant_id' => $tenant->id, 'email' => Str::random(10) . '@example.test',
            'password' => bcrypt('x'), 'password_hash' => bcrypt('x'), 'name' => 'Owner', 'role' => 'owner',
            'email_verified_at' => now(), 'locale' => 'en',
        ]);
        $this->actingAs($user, 'web')->get('/portal/my-chatbots')->assertOk()
            ->assertSee(__('chatbot_domain.rejections_label'))
            ->assertSee('khonehrangi.example.test');
    }

    public function test_made_up_origins_cannot_grow_the_rejection_table_without_bound(): void
    {
        // (Over HTTP the chat-message rate limit already stops this long
        // before the cap; the cap is the bound that holds regardless.)
        ['chatbotIds' => [$bot]] = $this->makeTenant();
        $index = DB::table('chatbot_index')->where('chatbot_id', $bot)->first();
        for ($i = 0; $i < \App\Support\DomainRejections::MAX_ORIGINS_PER_CHATBOT + 5; $i++) {
            \App\Support\DomainRejections::record($index, "fake{$i}.example.test");
        }
        $this->assertSame(\App\Support\DomainRejections::MAX_ORIGINS_PER_CHATBOT, DB::table('domain_rejections')->where('chatbot_id', $bot)->count());
    }

    public function test_a_chatbot_awaiting_its_domain_can_sync_but_its_widget_says_why_it_is_silent(): void
    {
        ['chatbotIds' => [$bot], 'raw' => $raw] = $this->makeTenant(domain: null);
        app(TenantService::class)->setChatbotActive($bot, true); // must not activate without a domain
        $this->assertSame('awaiting_domain', DB::table('chatbot_index')->where('chatbot_id', $bot)->value('disabled_reason'));

        $r = $this->postJson('/api/v1/chat/session', ['chatbot_id' => $bot, 'session_id' => 's1'], ['Origin' => 'https://anything.example.test']);
        $r->assertForbidden()->assertJsonPath('reason', 'awaiting_domain');
        $this->assertStringContainsString(__('chatbot_domain.awaiting_domain', [], 'fa'), $r->json('message'));

        // The plugin can still prepare content meanwhile.
        $this->postJson('/api/v1/sync/faqs', ['chatbot_id' => $bot, 'faqs' => [['question' => 'Q?', 'answer' => 'A.']]], $this->bearer($raw))->assertStatus(202);
        $this->postJson('/api/v1/connection-test', [], ['Authorization' => "Bearer {$raw}"])->assertStatus(409)->assertJsonPath('reason', 'awaiting_domain');
    }

    public function test_the_owner_registers_the_domain_in_the_portal_and_the_chatbot_switches_on(): void
    {
        ['tenant' => $tenant, 'chatbotIds' => [$bot]] = $this->makeTenant(domain: null);
        app(TenantService::class)->setChatbotActive($bot, false, 'awaiting_domain');
        $user = User::create([
            'tenant_id' => $tenant->id, 'email' => Str::random(10) . '@example.test',
            'password' => bcrypt('x'), 'password_hash' => bcrypt('x'), 'name' => 'Owner', 'role' => 'owner',
            'email_verified_at' => now(), 'locale' => 'en',
        ]);
        $this->actingAs($user, 'web');
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('customer'));
        $record = ChatbotIndexEntry::find($bot);

        Livewire::test(MyChatbots::class)
            ->callTableAction('register_domain', $record, ['domain' => 'not a domain'])
            ->assertHasTableActionErrors(['domain']);
        $this->assertNull(ChatbotIndexEntry::find($bot)->primary_domain);

        Livewire::test(MyChatbots::class)
            ->callTableAction('register_domain', $record, ['domain' => 'https://www.My-Shop.example.test/'])
            ->assertHasNoTableActionErrors();

        $entry = ChatbotIndexEntry::find($bot);
        $this->assertSame('my-shop.example.test', $entry->primary_domain);
        $this->assertTrue((bool) $entry->is_active);
        $this->assertNull($entry->disabled_reason);
    }

    public function test_creating_a_chatbot_through_the_api_requires_a_valid_domain(): void
    {
        ['tenant' => $tenant] = $this->makeTenant();
        $user = User::create([
            'tenant_id' => $tenant->id, 'email' => Str::random(10) . '@example.test',
            'password' => bcrypt('x'), 'password_hash' => bcrypt('x'), 'name' => 'Owner', 'role' => 'owner',
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/chatbots', ['name' => 'B', 'type' => 'support'])->assertStatus(422)->assertJsonValidationErrors('domain');
        $this->postJson('/api/v1/chatbots', ['name' => 'B', 'type' => 'support', 'domain' => '127.0.0.1'])->assertStatus(422)->assertJsonPath('reason', 'invalid_domain');

        $id = $this->postJson('/api/v1/chatbots', ['name' => 'B', 'type' => 'support', 'domain' => 'https://New.example.test/'])->assertCreated()->json('data.id');
        $this->assertSame('new.example.test', DB::table('chatbot_index')->where('chatbot_id', $id)->value('primary_domain'));
    }

    public function test_multi_domain_endpoints_are_gone(): void
    {
        ['tenant' => $tenant, 'chatbotIds' => [$bot]] = $this->makeTenant();
        $user = User::create([
            'tenant_id' => $tenant->id, 'email' => Str::random(10) . '@example.test',
            'password' => bcrypt('x'), 'password_hash' => bcrypt('x'), 'name' => 'Owner', 'role' => 'owner',
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/chatbots/{$bot}/domains", ['domain' => 'second.example.test'])->assertStatus(410)->assertJsonPath('reason', 'multi_domain_unsupported');
        $this->assertSame('shop.example.test', DB::table('chatbot_index')->where('chatbot_id', $bot)->value('primary_domain'));
    }

    // ── The data migrations ───────────────────────────────────────────

    public function test_the_migration_parks_only_active_chatbots_without_a_domain_and_can_be_rolled_back(): void
    {
        ['chatbotIds' => [$noDomain]] = $this->makeTenant(domain: null);
        ['chatbotIds' => [$withDomain]] = $this->makeTenant();
        ['chatbotIds' => [$expired]] = $this->makeTenant(domain: null);
        DB::table('chatbot_index')->where('chatbot_id', $expired)->update(['is_active' => false, 'disabled_reason' => 'expired']);

        $migration = require base_path('database/migrations/2026_11_08_000002_require_a_domain_for_every_active_chatbot.php');
        \Illuminate\Support\Facades\Schema::dropIfExists('domain_rejections'); // already created by RefreshDatabase
        $migration->up();

        $row = fn ($id) => DB::table('chatbot_index')->where('chatbot_id', $id)->first();
        $this->assertFalse((bool) $row($noDomain)->is_active);
        $this->assertSame('awaiting_domain', $row($noDomain)->disabled_reason);
        $this->assertTrue((bool) $row($withDomain)->is_active);
        $this->assertSame('expired', $row($expired)->disabled_reason, 'a chatbot off for another reason keeps that reason');

        $migration->down();
        $this->assertTrue((bool) $row($noDomain)->is_active);
        $this->assertSame('expired', $row($expired)->disabled_reason);
    }

    public function test_the_migration_binds_a_key_only_where_its_tenant_has_exactly_one_chatbot(): void
    {
        ['key' => $single, 'chatbotIds' => [$only]] = $this->makeTenant(bindKey: false);
        ['key' => $multi] = $this->makeTenant(chatbots: 2, bindKey: false);

        $migration = require base_path('database/migrations/2026_11_08_000003_bind_every_api_key_to_one_chatbot.php');
        $migration->up();

        $this->assertSame($only, $single->fresh()->chatbot_id);
        $this->assertNull($multi->fresh()->chatbot_id, 'ambiguous: left unbound (and refused) for support to bind by hand');
    }
}
