<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Tenant;
use App\Models\Plan;
use App\Services\TenantService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Real production incident on the live hamantech.ir chatbot: a customer
 * volunteered "شمارمو ثبت کن، تماس بگیرید 09371234567" unprompted (never
 * having been asked for it — pending_lead_question was never set), and the
 * message went straight into RAG. The bot literally answered "the number
 * 0937... isn't in our information". LeadCaptureService previously only
 * had the two-turn flow (isAwaitingContact()); this guards the new
 * independent check that runs on every message regardless of that state.
 */
class VolunteeredLeadCaptureTest extends TestCase
{
    use RefreshDatabase;

    private function makeChatbot(array $widgetConfig = []): array
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

    public function test_volunteered_phone_number_is_captured_without_ever_reaching_rag(): void
    {
        ['chatbotId' => $chatbotId, 'conversationId' => $conversationId, 'schema' => $schema] =
            $this->makeChatbot(['lead_capture_enabled' => true]);

        // No prior fakeUnansweredResponse()/pending_lead_question turn at
        // all — this is the very first message in the conversation, and
        // it's a phone number the customer gave unprompted.
        $response = $this->postJson('/api/v1/chat/message', [
            'chatbot_id' => $chatbotId, 'conversation_id' => $conversationId,
            'message' => 'شمارمو ثبت کن، تماس بگیرید 09371234567', 'session_id' => 'sess-1',
        ], ['Origin' => 'https://example.test']);

        $response->assertStatus(200);

        DB::statement("SET search_path TO {$schema}, public");
        $lead = DB::table('leads')->where('conversation_id', $conversationId)->first();
        DB::statement('SET search_path TO public');

        $this->assertNotNull($lead, 'No lead row was created for a volunteered phone number.');
        $this->assertEquals('09371234567', $lead->contact);
        $this->assertEquals('phone', $lead->contact_type);

        // The AI gateway must never have been called — a phone number is
        // not a question to run through RAG.
        Http::assertNothingSent();
    }

    public function test_volunteered_email_is_captured(): void
    {
        ['chatbotId' => $chatbotId, 'conversationId' => $conversationId, 'schema' => $schema] =
            $this->makeChatbot(['lead_capture_enabled' => true]);

        $this->postJson('/api/v1/chat/message', [
            'chatbot_id' => $chatbotId, 'conversation_id' => $conversationId,
            'message' => 'Please email me at sara@example.com about this', 'session_id' => 'sess-1',
        ], ['Origin' => 'https://example.test'])->assertStatus(200);

        DB::statement("SET search_path TO {$schema}, public");
        $lead = DB::table('leads')->where('conversation_id', $conversationId)->first();
        DB::statement('SET search_path TO public');

        $this->assertNotNull($lead);
        $this->assertEquals('sara@example.com', $lead->contact);
        $this->assertEquals('email', $lead->contact_type);
    }

    public function test_lead_question_field_summarizes_prior_conversation_turns(): void
    {
        ['chatbotId' => $chatbotId, 'conversationId' => $conversationId, 'schema' => $schema] =
            $this->makeChatbot(['lead_capture_enabled' => true]);

        Http::fake([
            '*/ai/chat/complete' => Http::response([
                'response' => 'We have that in stock.',
                'chunk_ids' => [], 'scores' => [], 'sources' => [],
                'prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0, 'cost_toman' => 0,
                'model' => 'n/a', 'latency_ms' => 5, 'is_fallback' => false, 'is_unanswered' => false,
                'finish_reason' => 'stop',
            ], 200),
        ]);

        // A real, answered exchange first — establishes what the
        // conversation was actually about.
        $this->postJson('/api/v1/chat/message', [
            'chatbot_id' => $chatbotId, 'conversation_id' => $conversationId,
            'message' => 'Do you sell the LM358N op-amp?', 'session_id' => 'sess-1',
        ], ['Origin' => 'https://example.test'])->assertStatus(200);

        // Then, unprompted, the customer leaves their number.
        $this->postJson('/api/v1/chat/message', [
            'chatbot_id' => $chatbotId, 'conversation_id' => $conversationId,
            'message' => '09371234567', 'session_id' => 'sess-1',
        ], ['Origin' => 'https://example.test'])->assertStatus(200);

        DB::statement("SET search_path TO {$schema}, public");
        $lead = DB::table('leads')->where('conversation_id', $conversationId)->first();
        DB::statement('SET search_path TO public');

        $this->assertNotNull($lead);
        $this->assertStringContainsString('LM358N', $lead->question, 'Lead question should summarize prior conversation context, not just the phone number itself.');
    }

    public function test_volunteered_contact_ignored_when_lead_capture_disabled(): void
    {
        // No widget_config — lead_capture_enabled defaults to false.
        ['chatbotId' => $chatbotId, 'conversationId' => $conversationId, 'schema' => $schema] = $this->makeChatbot();

        Http::fake([
            '*/ai/chat/complete' => Http::response([
                'response' => "The number 0937... isn't in our information.",
                'chunk_ids' => [], 'scores' => [], 'sources' => [],
                'prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0, 'cost_toman' => 0,
                'model' => 'n/a', 'latency_ms' => 5, 'is_fallback' => false, 'is_unanswered' => true,
                'finish_reason' => 'fallback',
            ], 200),
        ]);

        $this->postJson('/api/v1/chat/message', [
            'chatbot_id' => $chatbotId, 'conversation_id' => $conversationId,
            'message' => 'شمارمو ثبت کن، تماس بگیرید 09371234567', 'session_id' => 'sess-1',
        ], ['Origin' => 'https://example.test'])->assertStatus(200);

        DB::statement("SET search_path TO {$schema}, public");
        $leadExists = DB::table('leads')->where('conversation_id', $conversationId)->exists();
        DB::statement('SET search_path TO public');

        $this->assertFalse($leadExists, 'A lead must not be captured when lead_capture_enabled is off.');
        Http::assertSentCount(1); // falls through to RAG exactly like before this feature existed
    }
}
