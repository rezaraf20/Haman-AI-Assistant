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
 * Guards the exact regression from the pre-1.2.0 WordPress plugin outage:
 * that plugin's POST /v1/chat/message never sent chatbot_id, only
 * conversation_id + session_id + message. ChatController::sendMessage() and
 * ValidateChatbotDomain both used to require chatbot_id outright, so every
 * site still running the old plugin got a 422 on every single message the
 * moment domain validation shipped. chatbot_id is now nullable, with
 * ValidateChatbotDomain resolving it from conversation_id as a fallback
 * (see the 'legacy plugin request' log warning) — these two tests are what
 * would have caught that regression before it reached production.
 */
class LegacyPluginCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    private function makeChatbot(): array
    {
        $plan = Plan::create([
            'name' => 'Test Plan', 'slug' => 'test-' . Str::random(8),
            'price_monthly' => 0, 'max_chatbots' => 1, 'max_tokens_monthly' => 1000000,
            'is_active' => true, 'sort_order' => 0,
        ]);

        // 'id' isn't in Tenant::$fillable, so it can't be set via
        // Tenant::create(['id' => ...]) — mass assignment silently drops it
        // and HasUuid generates a random one instead. Let it generate, then
        // read $tenant->id back for everything downstream (schema name,
        // chatbot_index.tenant_id) instead of pre-deciding the ID.
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

        // Real schema, not a mock — this is exactly the DDL production uses
        // (TenantService::createTenantTables()), so the test exercises the
        // same search_path-switching code path the bug lived in.
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

        // primary_domain left null — enforcement is soft when unset (see
        // ValidateChatbotDomain), so any Origin header is accepted; these
        // tests are about chatbot_id resolution, not domain matching.
        DB::table('chatbot_index')->insert([
            'chatbot_id' => $chatbotId, 'tenant_id' => $tenant->id, 'schema_name' => $schema,
            'is_active' => true, 'name' => 'Test Bot', 'primary_domain' => null,
        ]);

        return compact('chatbotId', 'conversationId');
    }

    private function fakeAiGateway(): void
    {
        // Never call the real Groq/Gemini/python service from a test.
        Http::fake([
            '*/ai/chat/complete' => Http::response([
                'response' => 'test answer', 'chunk_ids' => [], 'scores' => [], 'sources' => [],
                'prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15, 'cost_toman' => 0,
                'model' => 'test/model', 'latency_ms' => 10, 'is_fallback' => false, 'finish_reason' => 'stop',
            ], 200),
        ]);
    }

    public function test_legacy_plugin_payload_without_chatbot_id_succeeds(): void
    {
        ['conversationId' => $conversationId] = $this->makeChatbot();
        $this->fakeAiGateway();

        $response = $this->postJson('/api/v1/chat/message', [
            'conversation_id' => $conversationId,
            'message' => 'hello',
            'session_id' => 'sess-1',
        ], ['Origin' => 'https://example.test']);

        $response->assertStatus(200);
    }

    public function test_new_plugin_payload_with_chatbot_id_succeeds(): void
    {
        ['chatbotId' => $chatbotId, 'conversationId' => $conversationId] = $this->makeChatbot();
        $this->fakeAiGateway();

        $response = $this->postJson('/api/v1/chat/message', [
            'chatbot_id' => $chatbotId,
            'conversation_id' => $conversationId,
            'message' => 'hello',
            'session_id' => 'sess-1',
        ], ['Origin' => 'https://example.test']);

        $response->assertStatus(200);
    }
}
