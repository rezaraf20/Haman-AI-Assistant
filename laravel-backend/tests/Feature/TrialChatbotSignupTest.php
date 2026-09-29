<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\{ApiKey, ChatbotIndexEntry, Plan};
use App\Models\Tenant\{Chatbot, Message};
use App\Services\TenantService;
use App\Support\Settings;
use Illuminate\Support\Facades\{Artisan, DB};
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Real reported gap: a brand-new signup created a Tenant and a User and
 * nothing else — zero chatbots — so the very first thing a merchant had to
 * do was navigate to BuyChatbot and pay from a wallet that starts at zero.
 * TenantService::createTrialChatbot() (called from both registerViaEmail()
 * and registerViaPhone()) closes that gap: every signup now reaches a real,
 * live, working chat widget immediately, capped by time and message count
 * instead of requiring payment first.
 */
class TrialChatbotSignupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Both registerViaEmail() and registerViaPhone() require this exact
        // slug to exist — see EmailLoginTest's identical setUp() comment.
        Plan::create([
            'name' => 'Free', 'slug' => 'free', 'price_monthly' => 0,
            'max_chatbots' => 1, 'max_tokens_monthly' => 100000,
            'is_active' => true, 'sort_order' => 0,
        ]);
    }

    /**
     * The phone path's OTP challenge already proves the contact detail
     * before registerViaPhone() is ever reached (see OtpLogin::verifyCode()),
     * so this is the one path that genuinely goes signup -> working widget
     * in a single unbroken step. The email path's version of this same
     * proof (test_an_email_trial_chatbot_works_once_verified below) needs
     * an explicit verification step in between — see
     * TenantService::createTrialChatbot()'s email_verified_at gate.
     */
    public function test_a_real_phone_signup_reaches_a_working_widget_session_immediately(): void
    {
        $result = app(TenantService::class)->registerViaPhone([
            'phone' => '09121234567', 'first_name' => 'Ali', 'last_name' => 'Rezaei',
            'email' => 'ali@example.test', 'national_id' => null, 'address' => null,
        ]);
        $chatbotId = DB::table('chatbot_index')->where('tenant_id', $result['tenant']->id)->value('chatbot_id');
        $this->assertNotNull($chatbotId, 'signup did not create a chatbot_index row at all');

        $response = $this->postJson('/api/v1/chat/session', [
            'chatbot_id' => $chatbotId,
            'session_id' => 'sess-trial-widget-1',
        ], ['Origin' => 'https://any-domain-at-all.test']);

        $response->assertStatus(201);
        $this->assertNotNull($response->json('data.conversation_id'), 'a real widget session did not get back a conversation id');
    }

    /** The abuse vector this closes: an unverified email must not reach a live widget at all. */
    public function test_an_unverified_email_trial_chatbot_does_not_answer_chat_requests(): void
    {
        $result = app(TenantService::class)->registerViaEmail([
            'name' => 'Jane Doe', 'email' => 'jane@example.test', 'password' => 'password123',
        ]);
        $chatbotId = DB::table('chatbot_index')->where('tenant_id', $result['tenant']->id)->value('chatbot_id');

        $response = $this->postJson('/api/v1/chat/session', [
            'chatbot_id' => $chatbotId,
            'session_id' => 'sess-unverified-1',
        ], ['Origin' => 'https://any-domain-at-all.test']);

        $response->assertStatus(404);
    }

    /** Same proof as the phone test, but for email: reachable only after the verification step actually runs. */
    public function test_an_email_trial_chatbot_works_once_verified(): void
    {
        $result = app(TenantService::class)->registerViaEmail([
            'name' => 'Jane Doe', 'email' => 'verified@example.test', 'password' => 'password123',
        ]);
        $tenant = $result['tenant'];
        $result['user']->forceFill(['email_verified_at' => now()])->save();
        app(TenantService::class)->activatePendingTrialChatbot($tenant);

        $chatbotId = DB::table('chatbot_index')->where('tenant_id', $tenant->id)->value('chatbot_id');
        $response = $this->postJson('/api/v1/chat/session', [
            'chatbot_id' => $chatbotId,
            'session_id' => 'sess-verified-1',
        ], ['Origin' => 'https://any-domain-at-all.test']);

        $response->assertStatus(201);
    }

    public function test_registering_via_email_creates_an_inactive_pending_verification_trial_chatbot_with_sane_defaults(): void
    {
        Settings::set('limits.trial_chatbot_duration_days', 14);
        $result = app(TenantService::class)->registerViaEmail([
            'name' => 'Jane Doe', 'email' => 'jane2@example.test', 'password' => 'password123',
        ]);
        $tenant = $result['tenant'];

        $index = ChatbotIndexEntry::where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($index);
        $this->assertFalse((bool) $index->is_active, 'an email trial chatbot must start inactive, pending verification');
        $this->assertEquals('pending_verification', $index->disabled_reason);
        $this->assertNull($index->primary_domain, 'a trial chatbot must not force the merchant to type a domain at signup');
        $this->assertEquals(0, $index->monthly_price_toman);
        $this->assertNotNull($index->expires_at);
        $this->assertEqualsWithDelta(now()->addDays(14)->timestamp, $index->expires_at->timestamp, 60);

        DB::statement("SET search_path TO {$tenant->schema_name}, public");
        $chatbot = Chatbot::find($index->chatbot_id);
        DB::statement('SET search_path TO public');
        $this->assertNotNull($chatbot);
        $this->assertEquals('trial', $chatbot->type);
        $this->assertFalse((bool) $chatbot->is_active);
        $this->assertEquals('en', $chatbot->language);

        $key = ApiKey::where('tenant_id', $tenant->id)->where('chatbot_id', $index->chatbot_id)->first();
        $this->assertNotNull($key, 'no API key was bound to the auto-created trial chatbot — the merchant could not install the plugin');
        $this->assertEquals($result['user']->id, $key->created_by);
    }

    public function test_registering_via_phone_creates_an_active_persian_trial_chatbot_immediately(): void
    {
        $result = app(TenantService::class)->registerViaPhone([
            'phone' => '09121234567', 'first_name' => 'Ali', 'last_name' => 'Rezaei',
            'email' => 'ali2@example.test', 'national_id' => null, 'address' => null,
        ]);
        $tenant = $result['tenant'];

        $index = ChatbotIndexEntry::where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($index);
        $this->assertTrue((bool) $index->is_active, 'the phone path is already verified by a real OTP challenge before this point — no extra gate needed');
        $this->assertNull($index->disabled_reason);

        DB::statement("SET search_path TO {$tenant->schema_name}, public");
        $chatbot = Chatbot::find($index->chatbot_id);
        DB::statement('SET search_path TO public');
        $this->assertTrue((bool) $chatbot->is_active);
        $this->assertEquals('trial', $chatbot->type);
        $this->assertEquals('fa', $chatbot->language);
    }

    public function test_verifying_the_email_activates_the_pending_trial_chatbot(): void
    {
        $result = app(TenantService::class)->registerViaEmail([
            'name' => 'Verify Me', 'email' => 'verifyme@example.test', 'password' => 'password123',
        ]);
        $tenant = $result['tenant'];

        app(TenantService::class)->activatePendingTrialChatbot($tenant);

        $index = ChatbotIndexEntry::where('tenant_id', $tenant->id)->first();
        $this->assertTrue((bool) $index->is_active);
        $this->assertNull($index->disabled_reason);

        DB::statement("SET search_path TO {$tenant->schema_name}, public");
        $chatbot = Chatbot::find($index->chatbot_id);
        DB::statement('SET search_path TO public');
        $this->assertTrue((bool) $chatbot->is_active);
    }

    /** A chatbot suspended for an unrelated reason (expired, over the message cap) must never be silently revived by a stale verification link. */
    public function test_activating_a_pending_trial_chatbot_never_revives_one_suspended_for_a_different_reason(): void
    {
        $result = app(TenantService::class)->registerViaEmail([
            'name' => 'Already Suspended', 'email' => 'suspended@example.test', 'password' => 'password123',
        ]);
        $tenant = $result['tenant'];
        $index = ChatbotIndexEntry::where('tenant_id', $tenant->id)->first();
        $index->update(['disabled_reason' => 'trial_message_limit_reached']);

        app(TenantService::class)->activatePendingTrialChatbot($tenant);

        $fresh = ChatbotIndexEntry::where('chatbot_id', $index->chatbot_id)->first();
        $this->assertFalse((bool) $fresh->is_active);
        $this->assertEquals('trial_message_limit_reached', $fresh->disabled_reason);
    }

    /** Proves the duration is genuinely read from settings, not a hardcoded 14. */
    public function test_trial_duration_is_configurable_not_hardcoded(): void
    {
        Settings::set('limits.trial_chatbot_duration_days', 5);

        $result = app(TenantService::class)->registerViaEmail([
            'name' => 'Short Trial', 'email' => 'short@example.test', 'password' => 'password123',
        ]);

        $index = ChatbotIndexEntry::where('tenant_id', $result['tenant']->id)->first();
        $this->assertEqualsWithDelta(now()->addDays(5)->timestamp, $index->expires_at->timestamp, 60);
    }

    /**
     * An already-active trial chatbot, for the enforcement-command tests
     * below — they test "does this command correctly suspend a chatbot
     * that started active," which the (unrelated) email-verification gate
     * would otherwise get in the way of. The phone path is always active
     * immediately (see test_registering_via_phone_creates_an_active_
     * persian_trial_chatbot_immediately above), so it sidesteps that
     * entirely without faking a verification step these tests aren't about.
     */
    private function makeTrialChatbot(): array
    {
        $result = app(TenantService::class)->registerViaPhone([
            'phone' => '0912' . random_int(1000000, 9999999), 'first_name' => 'Cap', 'last_name' => 'Test',
            'email' => Str::random(10) . '@example.test', 'national_id' => null, 'address' => null,
        ]);
        $tenant = $result['tenant'];
        $index = ChatbotIndexEntry::where('tenant_id', $tenant->id)->first();

        return ['tenant' => $tenant, 'index' => $index];
    }

    private function addUserMessages(string $schema, string $chatbotId, int $count): void
    {
        DB::statement("SET search_path TO {$schema}, public");
        $conversationId = (string) Str::uuid();
        DB::table('conversations')->insert([
            'id' => $conversationId, 'chatbot_id' => $chatbotId, 'session_id' => 'sess-' . Str::random(8),
            'status' => 'active', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        for ($i = 0; $i < $count; $i++) {
            Message::create([
                'conversation_id' => $conversationId, 'chatbot_id' => $chatbotId,
                'role' => 'user', 'content' => "message {$i}",
            ]);
        }
        DB::statement('SET search_path TO public');
    }

    public function test_enforce_message_limit_suspends_a_trial_chatbot_over_the_cap(): void
    {
        Settings::set('limits.trial_chatbot_message_limit', 3);
        ['tenant' => $tenant, 'index' => $index] = $this->makeTrialChatbot();
        $this->addUserMessages($tenant->schema_name, $index->chatbot_id, 3);

        Artisan::call('trial-chatbots:enforce-message-limit');

        $fresh = ChatbotIndexEntry::where('chatbot_id', $index->chatbot_id)->first();
        $this->assertFalse($fresh->is_active);
        $this->assertEquals('trial_message_limit_reached', $fresh->disabled_reason);
    }

    public function test_enforce_message_limit_leaves_a_trial_chatbot_under_the_cap_active(): void
    {
        Settings::set('limits.trial_chatbot_message_limit', 5);
        ['tenant' => $tenant, 'index' => $index] = $this->makeTrialChatbot();
        $this->addUserMessages($tenant->schema_name, $index->chatbot_id, 2);

        Artisan::call('trial-chatbots:enforce-message-limit');

        $fresh = ChatbotIndexEntry::where('chatbot_id', $index->chatbot_id)->first();
        $this->assertTrue($fresh->is_active);
        $this->assertNull($fresh->disabled_reason);
    }

    /** A paid (non-trial) chatbot must never be touched by the trial-only cap, no matter how many messages it has. */
    public function test_enforce_message_limit_never_touches_a_non_trial_chatbot(): void
    {
        Settings::set('limits.trial_chatbot_message_limit', 1);
        $plan = Plan::where('slug', 'free')->first();
        $tenant = \App\Models\Tenant::create([
            'slug' => 'paid-' . Str::random(6), 'name' => 'Paid Tenant',
            'email' => Str::random(10) . '@example.test', 'plan_id' => $plan->id,
            'schema_name' => 'placeholder', 'status' => 'active', 'trial_ends_at' => now()->addDays(14),
        ]);
        $schema = 'tenant_' . str_replace('-', '', $tenant->id);
        $tenant->update(['schema_name' => $schema]);
        app(TenantService::class)->createSchema($schema);

        // Raw insert, not Chatbot::create(): 'id' isn't in Chatbot::$fillable
        // (deliberately, same as every model using HasUuid), so a mass-assign
        // create() silently drops a pre-generated id and HasUuid mints a
        // different one instead — fine when the caller reads $model->id
        // back afterward (as createTrialChatbot() does), wrong here where
        // $chatbotId must be the exact id the later inserts reference.
        $chatbotId = (string) Str::uuid();
        DB::statement("SET search_path TO {$schema}, public");
        DB::table('chatbots')->insert([
            'id' => $chatbotId, 'name' => 'Paid Bot', 'type' => 'support', 'status' => 'active',
            'is_active' => true, 'language' => 'en', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement('SET search_path TO public');
        DB::table('chatbot_index')->insert([
            'chatbot_id' => $chatbotId, 'tenant_id' => $tenant->id, 'schema_name' => $schema,
            'is_active' => true, 'name' => 'Paid Bot', 'monthly_price_toman' => 50000,
        ]);
        $this->addUserMessages($schema, $chatbotId, 10);

        Artisan::call('trial-chatbots:enforce-message-limit');

        $this->assertTrue(ChatbotIndexEntry::where('chatbot_id', $chatbotId)->first()->is_active);
    }

    public function test_expire_overdue_command_sets_a_clear_disabled_reason(): void
    {
        ['tenant' => $tenant, 'index' => $index] = $this->makeTrialChatbot();
        $index->update(['expires_at' => now()->subDay()]);

        Artisan::call('chatbots:expire-overdue');

        $fresh = ChatbotIndexEntry::where('chatbot_id', $index->chatbot_id)->first();
        $this->assertFalse($fresh->is_active);
        $this->assertEquals('expired', $fresh->disabled_reason);
    }
}
