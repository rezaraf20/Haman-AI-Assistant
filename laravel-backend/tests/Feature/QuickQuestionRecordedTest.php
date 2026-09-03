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
 * Guards the fix for quick questions being answered entirely client-side in
 * the WordPress widget (class-hamman-public.php) — that path never called
 * /chat/message, so a visitor clicking a quick question left zero trace in
 * the messages table and was invisible to analytics/token accounting. The
 * fix routes a quick-question click through the exact same send path as a
 * hand-typed message; this test guards that a message sent with that same
 * content is actually persisted, i.e. that the recording side of the fix
 * (which is entirely server-side — "quick question" has no special meaning
 * to the API) keeps working regardless of what the widget UI does with it.
 */
class QuickQuestionRecordedTest extends TestCase
{
    use RefreshDatabase;

    private function makeChatbot(): array
    {
        $plan = Plan::create([
            'name' => 'Test Plan', 'slug' => 'test-' . Str::random(8),
            'price_monthly' => 0, 'max_chatbots' => 1, 'max_tokens_monthly' => 1000000,
            'is_active' => true, 'sort_order' => 0,
        ]);

        $tenant = Tenant::create([
            'slug' => 'test-' . Str::random(8),
            'name' => 'Test Tenant',
            'email' => Str::random(12) . '@example.test',
            'plan_id' => $plan->id,
            'schema_name' => 'placeholder',
            'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
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
            'widget_config' => json_encode(['quick_questions' => [
                ['question' => 'What are your business hours?', 'answer' => '9 to 5, Mon-Fri.'],
            ]]),
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

    public function test_quick_question_sent_through_chat_message_is_recorded(): void
    {
        ['chatbotId' => $chatbotId, 'conversationId' => $conversationId, 'schema' => $schema] = $this->makeChatbot();

        Http::fake([
            '*/ai/chat/complete' => Http::response([
                'response' => '9 to 5, Mon-Fri.', 'chunk_ids' => [], 'scores' => [], 'sources' => [],
                'prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15, 'cost_toman' => 0,
                'model' => 'test/model', 'latency_ms' => 10, 'is_fallback' => false, 'finish_reason' => 'stop',
            ], 200),
        ]);

        $quickQuestionText = 'What are your business hours?';

        $response = $this->postJson('/api/v1/chat/message', [
            'chatbot_id' => $chatbotId,
            'conversation_id' => $conversationId,
            'message' => $quickQuestionText,
            'session_id' => 'sess-1',
        ], ['Origin' => 'https://example.test']);

        $response->assertStatus(200);

        DB::statement("SET search_path TO {$schema}, public");
        $recorded = DB::table('messages')
            ->where('conversation_id', $conversationId)
            ->where('role', 'user')
            ->where('content', $quickQuestionText)
            ->exists();
        $assistantRecorded = DB::table('messages')
            ->where('conversation_id', $conversationId)
            ->where('role', 'assistant')
            ->exists();
        DB::statement('SET search_path TO public');

        $this->assertTrue($recorded, 'The quick-question text sent as a normal message was not found in the messages table.');
        $this->assertTrue($assistantRecorded, 'No assistant reply was recorded for the quick-question message.');
    }

    public function test_conversation_messages_endpoint_returns_persisted_history(): void
    {
        ['chatbotId' => $chatbotId, 'conversationId' => $conversationId, 'schema' => $schema] = $this->makeChatbot();

        DB::statement("SET search_path TO {$schema}, public");
        DB::table('messages')->insert([
            'id' => (string) Str::uuid(), 'conversation_id' => $conversationId, 'chatbot_id' => $chatbotId,
            'role' => 'user', 'content' => 'hello', 'total_tokens' => 0, 'created_at' => now(),
        ]);
        DB::statement('SET search_path TO public');

        $response = $this->getJson(
            "/api/v1/chat/conversation/{$conversationId}/messages?chatbot_id={$chatbotId}",
            ['Origin' => 'https://example.test']
        );

        $response->assertStatus(200)
            ->assertJsonPath('data.conversation_id', $conversationId)
            ->assertJsonCount(1, 'data.messages')
            ->assertJsonPath('data.messages.0.content', 'hello');
    }
}
