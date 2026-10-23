<?php
namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\Tenant\{Chatbot, Conversation};
use App\Services\ChatService;
use App\Services\TenantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ChatService::gatewayPayload() must hand python_ai a request_id — the
 * value AiGatewayService::post()'s ->retry() resends unchanged on every
 * attempt of the SAME logical message, and the one thing that lets
 * routers/chat.py::_dedupe_chat_request() tell "this is a retry of a
 * message already in flight" from "this is a genuinely new message". See
 * the 2026-10-22 incident this is the structural backstop for.
 */
class ChatRequestIdTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenantChatbotConversation(): array
    {
        $plan = Plan::create([
            'name' => 'Test Plan', 'slug' => 'test-' . Str::random(8),
            'price_monthly' => 0, 'max_chatbots' => 1, 'max_tokens_monthly' => 1000000,
            'is_active' => true, 'sort_order' => 0, 'allowed_tools' => [],
        ]);
        $tenant = Tenant::create([
            'slug' => 'test-' . Str::random(8), 'name' => 'Test Tenant',
            'email' => Str::random(12) . '@shop.example.com', 'plan_id' => $plan->id,
            'schema_name' => 'placeholder', 'status' => 'active', 'trial_ends_at' => now()->addDays(14),
            'settings' => ['webhook_secret' => Str::random(32)],
        ]);
        $schema = 'tenant_' . str_replace('-', '', $tenant->id);
        $tenant->update(['schema_name' => $schema]);
        app(TenantService::class)->createSchema($schema);

        $chatbotId = (string) Str::uuid();
        DB::statement("SET search_path TO {$schema}, public");
        DB::table('chatbots')->insert([
            'id' => $chatbotId, 'name' => 'Test Bot', 'type' => 'support', 'status' => 'active',
            'is_active' => true, 'language' => 'fa', 'enabled_tools' => json_encode([]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $chatbot = Chatbot::find($chatbotId);
        $conv = Conversation::create([
            'chatbot_id' => $chatbotId, 'session_id' => 'test-session-' . Str::random(8),
            'language' => 'fa', 'device_type' => 'desktop', 'status' => 'active', 'started_at' => now(),
        ]);
        // Left pointed at the tenant schema (plus public), not reset —
        // ChatController::setSchemaFromChatbot() is what does this in a
        // real request, and this test calls ChatService::sendMessage()
        // directly, bypassing the controller entirely.
        app()->instance('current_tenant', $tenant->fresh('plan'));

        return [$tenant->fresh('plan'), $chatbot, $conv];
    }

    public function test_the_payload_sent_to_python_ai_carries_a_uuid_request_id(): void
    {
        [, , $conv] = $this->makeTenantChatbotConversation();
        Http::fake(['*' => Http::response(['response' => 'ok', 'widget_blocks' => []], 200)]);

        app(ChatService::class)->sendMessage($conv, 'hello');

        Http::assertSent(function ($request) {
            $body = $request->data();
            return isset($body['request_id'])
                && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $body['request_id']) === 1;
        });
    }

    public function test_two_separate_messages_get_two_different_request_ids(): void
    {
        [, , $conv] = $this->makeTenantChatbotConversation();
        Http::fake(['*' => Http::response(['response' => 'ok', 'widget_blocks' => []], 200)]);

        app(ChatService::class)->sendMessage($conv, 'first message');
        app(ChatService::class)->sendMessage($conv, 'second message');

        $ids = collect(Http::recorded())->map(fn ($pair) => $pair[0]->data()['request_id'] ?? null)->all();
        $this->assertCount(2, $ids);
        $this->assertNotEquals($ids[0], $ids[1], 'two distinct customer messages must never share a request_id');
    }
}
