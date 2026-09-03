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
 * Guards the demand-gap dashboard's data source: when the Python RAG
 * service (rag_service.py) finds no chunk above the chatbot's own
 * retrieval_threshold, it returns is_unanswered=true in the /ai/chat/complete
 * response, and ChatService::sendMessage() must persist that onto the
 * assistant Message row — that column is what DemandGap.php counts and
 * groups on. Also guards that a fresh tenant schema (TenantService::
 * createTenantTables) actually has the column, since a tenant created before
 * this feature shipped only gets it via fixSchema()'s separate ALTER TABLE.
 */
class UnansweredMessageTest extends TestCase
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

        // Real schema creation, not a mock — this is the exact code path a
        // brand-new tenant goes through, which is what test_new_tenant_
        // schema_includes_is_unanswered_column() below is verifying.
        app(TenantService::class)->createSchema($schema);

        $chatbotId = (string) Str::uuid();
        $conversationId = (string) Str::uuid();

        DB::statement("SET search_path TO {$schema}, public");
        DB::table('chatbots')->insert([
            'id' => $chatbotId, 'name' => 'Test Bot', 'type' => 'support', 'status' => 'active',
            'is_active' => true, 'welcome_message' => 'hi', 'language' => 'en',
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

    public function test_new_tenant_schema_includes_is_unanswered_column(): void
    {
        ['schema' => $schema] = $this->makeChatbot();

        $columns = DB::select(
            "SELECT column_name, data_type, column_default FROM information_schema.columns
             WHERE table_schema = ? AND table_name = 'messages' AND column_name = 'is_unanswered'",
            [$schema]
        );

        $this->assertCount(1, $columns, 'A freshly created tenant schema is missing messages.is_unanswered.');
        $this->assertSame('boolean', $columns[0]->data_type);
    }

    public function test_below_threshold_response_is_persisted_as_unanswered(): void
    {
        ['chatbotId' => $chatbotId, 'conversationId' => $conversationId, 'schema' => $schema] = $this->makeChatbot();

        // Simulates rag_service.py's retrieval-gap branch: no chunk cleared
        // the chatbot's retrieval_threshold, so the RAG service reports
        // is_unanswered=true alongside its usual fallback response.
        Http::fake([
            '*/ai/chat/complete' => Http::response([
                'response' => 'Sorry, I do not have information about that.',
                'chunk_ids' => [], 'scores' => [], 'sources' => [],
                'prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0, 'cost_toman' => 0,
                'model' => 'n/a', 'latency_ms' => 5, 'is_fallback' => true, 'is_unanswered' => true,
                'finish_reason' => 'fallback',
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/chat/message', [
            'chatbot_id' => $chatbotId,
            'conversation_id' => $conversationId,
            'message' => 'Do you sell the XYZ-9000 model?',
            'session_id' => 'sess-1',
        ], ['Origin' => 'https://example.test']);

        $response->assertStatus(200);

        DB::statement("SET search_path TO {$schema}, public");
        $flagged = DB::table('messages')
            ->where('conversation_id', $conversationId)
            ->where('role', 'assistant')
            ->where('is_unanswered', true)
            ->exists();
        DB::statement('SET search_path TO public');

        $this->assertTrue($flagged, 'The assistant message was not persisted with is_unanswered=true.');
    }

    public function test_normal_answered_response_is_not_flagged_unanswered(): void
    {
        ['chatbotId' => $chatbotId, 'conversationId' => $conversationId, 'schema' => $schema] = $this->makeChatbot();

        Http::fake([
            '*/ai/chat/complete' => Http::response([
                'response' => 'We are open 9 to 5, Monday through Friday.',
                'chunk_ids' => ['c1'], 'scores' => [0.82], 'sources' => [],
                'prompt_tokens' => 10, 'completion_tokens' => 8, 'total_tokens' => 18, 'cost_toman' => 0,
                'model' => 'test/model', 'latency_ms' => 12, 'is_fallback' => false, 'is_unanswered' => false,
                'finish_reason' => 'stop',
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/chat/message', [
            'chatbot_id' => $chatbotId,
            'conversation_id' => $conversationId,
            'message' => 'What are your business hours?',
            'session_id' => 'sess-1',
        ], ['Origin' => 'https://example.test']);

        $response->assertStatus(200);

        DB::statement("SET search_path TO {$schema}, public");
        $flagged = DB::table('messages')
            ->where('conversation_id', $conversationId)
            ->where('role', 'assistant')
            ->where('is_unanswered', true)
            ->exists();
        DB::statement('SET search_path TO public');

        $this->assertFalse($flagged, 'A normally grounded response was incorrectly flagged as unanswered.');
    }
}
