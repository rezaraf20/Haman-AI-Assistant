<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Tenant;
use App\Models\Plan;
use App\Models\User;
use App\Services\TenantService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * "If we leave someone unanswered in live chat, we lose access to them" —
 * the real interview quote behind this feature. Guards the full two-turn
 * flow: an unanswered query (opt-in per chatbot via
 * widget_config.lead_capture_enabled) prompts for contact info instead of
 * just saying "I don't know"; the next message is read as that contact
 * attempt, not a new question; a valid one creates a leads row, logs
 * lead_captured, and fires configured notifications; an invalid one is
 * rejected and re-prompts instead of silently accepting garbage.
 */
class LeadCaptureTest extends TestCase
{
    use RefreshDatabase;

    private function makeChatbot(array $widgetConfig = [], array $notificationSettings = []): array
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
        $conversationId = (string) Str::uuid();

        DB::statement("SET search_path TO {$schema}, public");
        DB::table('chatbots')->insert([
            'id' => $chatbotId, 'name' => 'Test Bot', 'type' => 'support', 'status' => 'active',
            'is_active' => true, 'welcome_message' => 'hi', 'language' => 'en',
            'widget_config' => json_encode($widgetConfig),
            'notification_settings' => json_encode($notificationSettings),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('conversations')->insert([
            'id' => $conversationId, 'chatbot_id' => $chatbotId, 'session_id' => 'sess-1',
            'status' => 'active', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement('SET search_path TO public');

        DB::table('chatbot_index')->insert([
            'chatbot_id' => $chatbotId, 'tenant_id' => $tenant->id, 'schema_name' => $schema,
            'is_active' => true, 'name' => 'Test Bot', 'primary_domain' => null,
        ]);

        return compact('chatbotId', 'conversationId', 'schema', 'tenant');
    }

    private function fakeUnansweredResponse(): void {
        Http::fake([
            '*/ai/chat/complete' => Http::response([
                'response' => "Sorry, I don't have that information.",
                'chunk_ids' => [], 'scores' => [], 'sources' => [],
                'prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0, 'cost_toman' => 0,
                'model' => 'n/a', 'latency_ms' => 5, 'is_fallback' => true, 'is_unanswered' => true,
                'finish_reason' => 'fallback',
            ], 200),
            'https://webhook.example.test/*' => Http::response(['ok' => true], 200),
        ]);
    }

    public function test_new_tenant_schema_includes_leads_table_and_columns(): void
    {
        ['schema' => $schema] = $this->makeChatbot();

        $exists = DB::selectOne(
            "SELECT EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = 'leads') AS exists",
            [$schema]
        )->exists;
        $this->assertTrue((bool) $exists, 'leads was not created in the schema template for a brand-new tenant.');

        $pendingCol = DB::selectOne(
            "SELECT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = ? AND table_name = 'conversations' AND column_name = 'pending_lead_question') AS exists",
            [$schema]
        )->exists;
        $this->assertTrue((bool) $pendingCol, 'conversations.pending_lead_question was not created.');

        $notifCol = DB::selectOne(
            "SELECT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = ? AND table_name = 'chatbots' AND column_name = 'notification_settings') AS exists",
            [$schema]
        )->exists;
        $this->assertTrue((bool) $notifCol, 'chatbots.notification_settings was not created.');
    }

    public function test_unanswered_query_prompts_for_contact_when_enabled(): void
    {
        ['chatbotId' => $chatbotId, 'conversationId' => $conversationId, 'schema' => $schema] =
            $this->makeChatbot(['lead_capture_enabled' => true]);
        $this->fakeUnansweredResponse();

        $response = $this->postJson('/api/v1/chat/message', [
            'chatbot_id' => $chatbotId, 'conversation_id' => $conversationId,
            'message' => 'Do you carry the XR-9000 capacitor?', 'session_id' => 'sess-1',
        ], ['Origin' => 'https://example.test']);

        $response->assertStatus(200);
        $this->assertStringContainsString('phone number or email', $response->json('data.response'));

        DB::statement("SET search_path TO {$schema}, public");
        $pending = DB::table('conversations')->where('id', $conversationId)->value('pending_lead_question');
        DB::statement('SET search_path TO public');

        $this->assertEquals('Do you carry the XR-9000 capacitor?', $pending);
    }

    public function test_valid_phone_number_creates_lead_logs_event_and_notifies(): void
    {
        ['chatbotId' => $chatbotId, 'conversationId' => $conversationId, 'schema' => $schema] = $this->makeChatbot(
            ['lead_capture_enabled' => true],
            ['webhook' => ['enabled' => true, 'url' => 'https://webhook.example.test/hook', 'events' => ['lead_captured']]]
        );
        $this->fakeUnansweredResponse();

        // Turn 1: unanswered question arms pending_lead_question.
        $this->postJson('/api/v1/chat/message', [
            'chatbot_id' => $chatbotId, 'conversation_id' => $conversationId,
            'message' => 'Do you carry the XR-9000 capacitor?', 'session_id' => 'sess-1',
        ], ['Origin' => 'https://example.test'])->assertStatus(200);

        // Turn 2: a real Iranian mobile number as the "message" — must be
        // read as the contact attempt, never reach the AI gateway at all.
        $response = $this->postJson('/api/v1/chat/message', [
            'chatbot_id' => $chatbotId, 'conversation_id' => $conversationId,
            'message' => '0912 345 6789', 'session_id' => 'sess-1',
        ], ['Origin' => 'https://example.test']);

        $response->assertStatus(200);
        $this->assertStringContainsString("We've got your details", $response->json('data.response'));

        DB::statement("SET search_path TO {$schema}, public");
        $lead = DB::table('leads')->where('conversation_id', $conversationId)->first();
        $pending = DB::table('conversations')->where('id', $conversationId)->value('pending_lead_question');
        $leadEvent = DB::table('conversation_events')
            ->where('conversation_id', $conversationId)->where('event_type', 'lead_captured')->first();
        DB::statement('SET search_path TO public');

        $this->assertNotNull($lead, 'No lead row was created for a valid phone number.');
        $this->assertEquals('09123456789', $lead->contact);
        $this->assertEquals('phone', $lead->contact_type);
        $this->assertEquals('Do you carry the XR-9000 capacitor?', $lead->question);
        $this->assertEquals('new', $lead->status);
        $this->assertNull($pending, 'pending_lead_question was not cleared after a successful capture.');
        $this->assertNotNull($leadEvent, 'No lead_captured conversation_event was logged.');

        Http::assertSent(fn ($request) => $request->url() === 'https://webhook.example.test/hook'
            && $request['event'] === 'lead_captured'
            && $request['data']['contact'] === '09123456789');

        // The AI gateway must never have been called for the contact-info
        // turn — only once, for the original unanswered question.
        Http::assertSentCount(2); // 1 chat/complete + 1 webhook
    }

    public function test_invalid_contact_is_rejected_and_conversation_stays_pending(): void
    {
        ['chatbotId' => $chatbotId, 'conversationId' => $conversationId, 'schema' => $schema] =
            $this->makeChatbot(['lead_capture_enabled' => true]);
        $this->fakeUnansweredResponse();

        $this->postJson('/api/v1/chat/message', [
            'chatbot_id' => $chatbotId, 'conversation_id' => $conversationId,
            'message' => 'Do you carry the XR-9000 capacitor?', 'session_id' => 'sess-1',
        ], ['Origin' => 'https://example.test'])->assertStatus(200);

        $response = $this->postJson('/api/v1/chat/message', [
            'chatbot_id' => $chatbotId, 'conversation_id' => $conversationId,
            'message' => '0912123', 'session_id' => 'sess-1',
        ], ['Origin' => 'https://example.test']);

        $response->assertStatus(200);
        $this->assertStringContainsString("doesn't look like a valid", $response->json('data.response'));

        DB::statement("SET search_path TO {$schema}, public");
        $leadExists = DB::table('leads')->where('conversation_id', $conversationId)->exists();
        $pending = DB::table('conversations')->where('id', $conversationId)->value('pending_lead_question');
        DB::statement('SET search_path TO public');

        $this->assertFalse($leadExists, 'A lead was created from an invalid contact string.');
        $this->assertEquals('Do you carry the XR-9000 capacitor?', $pending, 'pending_lead_question was cleared despite an invalid attempt.');
    }

    public function test_a_new_question_ends_the_ask_instead_of_looping(): void
    {
        // The reported conversation: the bot asked for a phone number, the
        // visitor carried on asking things, and every message came back
        // "that doesn't look like a valid phone number or email" because
        // nothing ever cleared the pending state.
        ['chatbotId' => $chatbotId, 'conversationId' => $conversationId, 'schema' => $schema] =
            $this->makeChatbot(['lead_capture_enabled' => true]);
        $this->fakeUnansweredResponse();

        $this->postJson('/api/v1/chat/message', [
            'chatbot_id' => $chatbotId, 'conversation_id' => $conversationId,
            'message' => 'خدمات uiux هم ارائه می‌دید؟', 'session_id' => 'sess-1',
        ], ['Origin' => 'https://example.test'])->assertStatus(200);

        $response = $this->postJson('/api/v1/chat/message', [
            'chatbot_id' => $chatbotId, 'conversation_id' => $conversationId,
            'message' => 'خدماتتون چیا هست؟', 'session_id' => 'sess-1',
        ], ['Origin' => 'https://example.test']);

        $response->assertStatus(200);
        $this->assertStringNotContainsString(
            "doesn't look like a valid",
            (string) $response->json('data.response'),
            'a question was answered as though it were a mistyped phone number',
        );

        DB::statement("SET search_path TO {$schema}, public");
        $pending = DB::table('conversations')->where('id', $conversationId)->value('pending_lead_question');
        DB::statement('SET search_path TO public');

        $this->assertNull($pending, 'the conversation is still waiting for a phone number, so the loop remains');
    }

    public function test_it_asks_for_contact_once_and_not_after_every_message(): void
    {
        // The other half of the reported loop: with the ask re-armed on each
        // unanswered answer, the visitor was asked for a number over and
        // over. Once per conversation is enough; after that the ordinary
        // fallback is the honest reply.
        ['chatbotId' => $chatbotId, 'conversationId' => $conversationId] =
            $this->makeChatbot(['lead_capture_enabled' => true]);
        $this->fakeUnansweredResponse();

        $first = $this->postJson('/api/v1/chat/message', [
            'chatbot_id' => $chatbotId, 'conversation_id' => $conversationId,
            'message' => 'خدمات uiux هم ارائه می‌دید؟', 'session_id' => 'sess-1',
        ], ['Origin' => 'https://example.test']);
        $this->assertStringContainsString('phone number or email', (string) $first->json('data.response'));

        // Carry on asking questions rather than answering with a number.
        $asks = 0;
        foreach (['خدماتتون چیا هست؟', 'خدماتتون رو بگو', 'خدمات'] as $message) {
            $response = $this->postJson('/api/v1/chat/message', [
                'chatbot_id' => $chatbotId, 'conversation_id' => $conversationId,
                'message' => $message, 'session_id' => 'sess-1',
            ], ['Origin' => 'https://example.test']);

            if (str_contains((string) $response->json('data.response'), 'phone number or email')) {
                $asks++;
            }
        }

        $this->assertSame(0, $asks, 'the visitor was asked for their number again after declining once');
    }

    public function test_every_message_from_the_reported_conversation_escapes(): void
    {
        // Verbatim from the transcript, in order. None of these is a phone
        // number, so none of them should be judged as one.
        ['chatbotId' => $chatbotId, 'conversationId' => $conversationId] =
            $this->makeChatbot(['lead_capture_enabled' => true]);
        $this->fakeUnansweredResponse();

        $this->postJson('/api/v1/chat/message', [
            'chatbot_id' => $chatbotId, 'conversation_id' => $conversationId,
            'message' => 'خدمات', 'session_id' => 'sess-1',
        ], ['Origin' => 'https://example.test'])->assertStatus(200);

        foreach (['الو', 'قیمت میدی؟', 'خدماتتون رو بگو'] as $message) {
            $response = $this->postJson('/api/v1/chat/message', [
                'chatbot_id' => $chatbotId, 'conversation_id' => $conversationId,
                'message' => $message, 'session_id' => 'sess-1',
            ], ['Origin' => 'https://example.test']);

            $this->assertStringNotContainsString(
                "doesn't look like a valid",
                (string) $response->json('data.response'),
                "\"{$message}\" was treated as a failed contact attempt",
            );
        }
    }

    public function test_a_volunteered_number_is_still_captured_after_the_ask(): void
    {
        // The escape hatch must not cost the thing this feature is for.
        ['chatbotId' => $chatbotId, 'conversationId' => $conversationId, 'schema' => $schema] =
            $this->makeChatbot(['lead_capture_enabled' => true]);
        $this->fakeUnansweredResponse();

        $this->postJson('/api/v1/chat/message', [
            'chatbot_id' => $chatbotId, 'conversation_id' => $conversationId,
            'message' => 'Do you carry the XR-9000 capacitor?', 'session_id' => 'sess-1',
        ], ['Origin' => 'https://example.test'])->assertStatus(200);

        $this->postJson('/api/v1/chat/message', [
            'chatbot_id' => $chatbotId, 'conversation_id' => $conversationId,
            'message' => '09376808058', 'session_id' => 'sess-1',
        ], ['Origin' => 'https://example.test'])->assertStatus(200);

        DB::statement("SET search_path TO {$schema}, public");
        $lead = DB::table('leads')->where('conversation_id', $conversationId)->first();
        DB::statement('SET search_path TO public');

        $this->assertNotNull($lead, 'a real phone number given after the ask was not captured');
        $this->assertEquals('09376808058', $lead->contact);
    }

    public function test_lead_capture_disabled_by_default_keeps_plain_fallback(): void
    {
        // No widget_config at all — lead_capture_enabled defaults to false,
        // matching every chatbot that existed before this feature shipped.
        ['chatbotId' => $chatbotId, 'conversationId' => $conversationId] = $this->makeChatbot();
        $this->fakeUnansweredResponse();

        $response = $this->postJson('/api/v1/chat/message', [
            'chatbot_id' => $chatbotId, 'conversation_id' => $conversationId,
            'message' => 'Do you carry the XR-9000 capacitor?', 'session_id' => 'sess-1',
        ], ['Origin' => 'https://example.test']);

        $response->assertStatus(200);
        $this->assertEquals("Sorry, I don't have that information.", $response->json('data.response'));
    }

    public function test_leads_portal_page_renders_without_raw_translation_keys(): void
    {
        ['tenant' => $tenant] = $this->makeChatbot();
        $customer = User::create([
            'tenant_id' => $tenant->id, 'email' => Str::random(10) . '@example.test',
            'password' => bcrypt('irrelevant'), 'password_hash' => bcrypt('irrelevant'),
            'name' => 'Test Customer', 'role' => 'owner', 'email_verified_at' => now(),
        ]);

        foreach (['fa', 'en'] as $locale) {
            $customer->update(['locale' => $locale]);
            $response = $this->actingAs($customer, 'web')->get('/portal/leads');
            $response->assertOk();
            preg_match_all('/\bleads\.[a-z_0-9]+\b/', $response->getContent(), $matches);
            $this->assertEmpty($matches[0], "Raw leads.* translation key(s) leaked on /portal/leads ({$locale}): " . implode(', ', $matches[0]));
        }
    }
}
