<?php
namespace Tests\Feature;

use App\Models\{Tenant, Plan};
use App\Services\TenantService;
use Illuminate\Support\Facades\{DB, Http};
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression test for a real production bug (2026-10-07, hamantech.ir,
 * "محل شرکت کجاست؟"): ChatService::sendMessage() discarded a real,
 * meaningful, business-profile-grounded answer and replaced it with the
 * generic lead-capture prompt — because is_unanswered is a pure retrieval
 * signal, with no awareness that the system prompt's business-profile
 * block might already have answered the question.
 *
 * The fix (ChatService::leadCapturePromptIfApplicable()) must never again
 * regress to using is_unanswered alone as the trigger for discarding a
 * response: once a chatbot has a business profile, its own real answer is
 * kept, and is_unanswered stays true regardless (it must still fire — see
 * DemandGap's own reliability warning — the gap is still real information
 * for the merchant even when the model answered from the profile instead
 * of an indexed page).
 */
class BusinessProfileLeadCaptureInteractionTest extends TestCase
{
    use RefreshDatabase;

    private function makeChatbot(array $businessProfile): array
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
            'widget_config' => json_encode(['lead_capture_enabled' => true]),
            'business_profile' => json_encode($businessProfile),
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

        return compact('chatbotId', 'conversationId', 'schema');
    }

    /** Retrieval found nothing (is_unanswered=true), but the model answered anyway — exactly what happens once a business profile is in the system prompt. */
    private function fakeGatewayRespondingWithARealAnswerDespiteBeingUnanswered(string $answer): void
    {
        Http::fake([
            '*/ai/chat/complete' => Http::response([
                'response' => $answer,
                'chunk_ids' => [], 'scores' => [], 'sources' => [],
                'prompt_tokens' => 10, 'completion_tokens' => 10, 'total_tokens' => 20, 'cost_toman' => 5,
                'model' => 'test-model', 'latency_ms' => 500, 'is_fallback' => true, 'is_unanswered' => true,
                'finish_reason' => 'stop',
            ], 200),
        ]);
    }

    public function test_a_business_profile_answer_is_preserved_even_when_retrieval_is_unanswered(): void
    {
        ['chatbotId' => $chatbotId, 'conversationId' => $conversationId, 'schema' => $schema] = $this->makeChatbot([
            'address' => ['en' => 'Unit 4, No. 123 Valiasr St, Tehran'],
        ]);
        $realAnswer = "We're based in Tehran, at Unit 4, No. 123 Valiasr Street.";
        $this->fakeGatewayRespondingWithARealAnswerDespiteBeingUnanswered($realAnswer);

        $response = $this->postJson('/api/v1/chat/message', [
            'chatbot_id' => $chatbotId, 'conversation_id' => $conversationId,
            'message' => 'Where is your company located?', 'session_id' => 'sess-1',
        ], ['Origin' => 'https://example.test']);

        $response->assertStatus(200);

        // The real fix: the customer-facing text is the model's real
        // answer, not the generic lead-capture prompt.
        $this->assertSame($realAnswer, $response->json('data.response'));
        $this->assertStringNotContainsString('phone number or email', $response->json('data.response'));

        // The gap signal must still fire regardless — see DemandGap's own
        // reliability warning for why this is deliberate, not a leftover bug.
        DB::statement("SET search_path TO {$schema}, public");
        $stored = DB::table('messages')->where('role', 'assistant')->where('conversation_id', $conversationId)->first();
        DB::statement('SET search_path TO public');

        $this->assertSame($realAnswer, $stored->content, 'The stored message must match what the customer saw.');
        $this->assertTrue((bool) $stored->is_unanswered, 'is_unanswered must still be true — the gap stays visible even though the profile answered it.');
    }

    public function test_without_a_business_profile_the_lead_capture_override_still_applies(): void
    {
        // Confirms the fix is scoped to chatbots with a profile, not a
        // blanket removal of lead capture's own purpose: a chatbot with
        // no profile at all still needs the override for genuinely
        // unanswered questions (e.g. product catalog gaps).
        ['chatbotId' => $chatbotId, 'conversationId' => $conversationId] = $this->makeChatbot([]);
        $this->fakeGatewayRespondingWithARealAnswerDespiteBeingUnanswered('Some incidental text the model produced anyway.');

        $response = $this->postJson('/api/v1/chat/message', [
            'chatbot_id' => $chatbotId, 'conversation_id' => $conversationId,
            'message' => 'Do you carry the XR-9000 capacitor?', 'session_id' => 'sess-1',
        ], ['Origin' => 'https://example.test']);

        $response->assertStatus(200);
        $this->assertStringContainsString('phone number or email', $response->json('data.response'));
    }
}
