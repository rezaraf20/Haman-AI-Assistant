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
 * conversation_events existed as a spec (columns for products_recommended,
 * conversions, escalation_count on analytics_daily) with nothing anywhere
 * ever writing to it — this covers the two event types Laravel itself is
 * responsible for logging (conversation_started, feedback) and that the
 * table actually ships in a brand-new tenant's schema template. The Python-
 * side events (retrieval/response/unanswered/product_mentioned) live in
 * rag_service.py and aren't reachable from a PHPUnit test — those are
 * verified against a real conversation separately.
 */
class ConversationEventsTest extends TestCase
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

        DB::statement("SET search_path TO {$schema}, public");
        DB::table('chatbots')->insert([
            'id' => $chatbotId, 'name' => 'Test Bot', 'type' => 'support', 'status' => 'active',
            'is_active' => true, 'welcome_message' => 'hi', 'language' => 'en',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement('SET search_path TO public');

        DB::table('chatbot_index')->insert([
            'chatbot_id' => $chatbotId, 'tenant_id' => $tenant->id, 'schema_name' => $schema,
            'is_active' => true, 'name' => 'Test Bot', 'primary_domain' => null,
        ]);

        return compact('chatbotId', 'schema', 'tenant');
    }

    public function test_conversation_events_table_exists_in_a_fresh_tenant_schema(): void
    {
        $schema = 'tenant_' . str_replace('-', '', (string) Str::uuid());
        app(TenantService::class)->createSchema($schema);

        $exists = DB::selectOne(
            "SELECT EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = 'conversation_events') AS exists",
            [$schema]
        )->exists;

        $this->assertTrue((bool) $exists, "conversation_events was not created in the schema template for a brand-new tenant ({$schema}).");

        $columns = DB::select(
            "SELECT column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = 'conversation_events'",
            [$schema]
        );
        $columnNames = array_column($columns, 'column_name');
        foreach (['id', 'conversation_id', 'message_id', 'chatbot_id', 'event_type', 'payload', 'latency_ms', 'created_at'] as $expected) {
            $this->assertContains($expected, $columnNames, "conversation_events is missing the '{$expected}' column.");
        }
    }

    public function test_creating_a_new_session_logs_a_conversation_started_event(): void
    {
        ['chatbotId' => $chatbotId, 'schema' => $schema] = $this->makeChatbot();

        $response = $this->postJson('/api/v1/chat/session', [
            'chatbot_id' => $chatbotId,
            'session_id' => 'sess-events-1',
        ], ['Origin' => 'https://example.test']);

        $response->assertStatus(201);
        $conversationId = $response->json('data.conversation_id');

        DB::statement("SET search_path TO {$schema}, public");
        $event = DB::table('conversation_events')
            ->where('conversation_id', $conversationId)
            ->where('event_type', 'conversation_started')
            ->first();
        DB::statement('SET search_path TO public');

        $this->assertNotNull($event, 'No conversation_started event was logged for a brand-new session.');
        $payload = json_decode($event->payload, true);
        $this->assertArrayHasKey('source', $payload);

        // A second /chat/session call with the same session_id hits the
        // "already exists" branch — must not log a second
        // conversation_started for the same conversation.
        $this->postJson('/api/v1/chat/session', [
            'chatbot_id' => $chatbotId,
            'session_id' => 'sess-events-1',
        ], ['Origin' => 'https://example.test'])->assertStatus(200);

        DB::statement("SET search_path TO {$schema}, public");
        $count = DB::table('conversation_events')
            ->where('conversation_id', $conversationId)
            ->where('event_type', 'conversation_started')
            ->count();
        DB::statement('SET search_path TO public');
        $this->assertEquals(1, $count, 'A repeat /chat/session call for the same session_id logged a duplicate conversation_started event.');
    }

    public function test_submitting_feedback_logs_a_feedback_event(): void
    {
        ['chatbotId' => $chatbotId, 'schema' => $schema] = $this->makeChatbot();

        $conversationId = (string) Str::uuid();
        $messageId = (string) Str::uuid();
        DB::statement("SET search_path TO {$schema}, public");
        DB::table('conversations')->insert([
            'id' => $conversationId, 'chatbot_id' => $chatbotId, 'session_id' => 'sess-fb-1',
            'status' => 'active', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('messages')->insert([
            'id' => $messageId, 'conversation_id' => $conversationId, 'chatbot_id' => $chatbotId,
            'role' => 'assistant', 'content' => 'the answer', 'total_tokens' => 0, 'created_at' => now(),
        ]);
        DB::statement('SET search_path TO public');

        $response = $this->postJson('/api/v1/chat/feedback', [
            'chatbot_id' => $chatbotId,
            'message_id' => $messageId,
            'rating' => 1,
        ], ['Origin' => 'https://example.test']);

        $response->assertStatus(200);

        DB::statement("SET search_path TO {$schema}, public");
        $event = DB::table('conversation_events')
            ->where('message_id', $messageId)
            ->where('event_type', 'feedback')
            ->first();
        DB::statement('SET search_path TO public');

        $this->assertNotNull($event, 'No feedback event was logged — submitFeedback() previously discarded this entirely.');
        $this->assertEquals($conversationId, $event->conversation_id);
        $this->assertEquals(1, json_decode($event->payload, true)['rating']);
    }
}
