<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Tenant;
use App\Models\Plan;
use App\Services\TenantService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * ChatController::cartEvent() — the add_to_cart tool's real confirmed
 * outcome (see tool_calling_service.py's docstring: the tool call itself
 * logs nothing, since nothing has happened yet; this endpoint is what the
 * widget calls AFTER a genuine browser-side WooCommerce Store API success,
 * see handleAddToCartClick()/onAddToCartSuccess() in haman-widget.js).
 * conversation_id is client-supplied, so it's re-validated against this
 * chatbot's own conversations table before anything is logged — same
 * "never trust a client-side id blindly" posture as SyncService::
 * recordOrder() for order webhooks.
 */
class CartEventTest extends TestCase
{
    use RefreshDatabase;

    private function makeChatbotWithConversation(): array
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
            'is_active' => true, 'language' => 'en', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('conversations')->insert([
            'id' => $conversationId, 'chatbot_id' => $chatbotId, 'session_id' => 'sess-atc-1',
            'status' => 'active', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement('SET search_path TO public');

        DB::table('chatbot_index')->insert([
            'chatbot_id' => $chatbotId, 'tenant_id' => $tenant->id, 'schema_name' => $schema,
            'is_active' => true, 'name' => 'Test Bot', 'primary_domain' => null,
        ]);

        return compact('chatbotId', 'conversationId', 'schema', 'tenant');
    }

    public function test_a_real_confirmed_add_logs_cart_add_succeeded(): void
    {
        ['chatbotId' => $chatbotId, 'conversationId' => $conversationId, 'schema' => $schema] = $this->makeChatbotWithConversation();

        $response = $this->postJson('/api/v1/chat/cart-event', [
            'chatbot_id' => $chatbotId,
            'conversation_id' => $conversationId,
            'product_id' => 12,
            'variation_id' => 34,
        ]);

        $response->assertStatus(200);

        DB::statement("SET search_path TO {$schema}, public");
        $event = DB::table('conversation_events')
            ->where('conversation_id', $conversationId)
            ->where('event_type', 'cart_add_succeeded')
            ->first();
        DB::statement('SET search_path TO public');

        $this->assertNotNull($event, 'No cart_add_succeeded event was logged.');
        $payload = json_decode($event->payload, true);
        $this->assertEquals(12, $payload['product_id']);
        $this->assertEquals(34, $payload['variation_id']);
    }

    public function test_variation_id_is_optional_for_a_simple_product(): void
    {
        ['chatbotId' => $chatbotId, 'conversationId' => $conversationId, 'schema' => $schema] = $this->makeChatbotWithConversation();

        $response = $this->postJson('/api/v1/chat/cart-event', [
            'chatbot_id' => $chatbotId,
            'conversation_id' => $conversationId,
            'product_id' => 12,
        ]);

        $response->assertStatus(200);

        DB::statement("SET search_path TO {$schema}, public");
        $event = DB::table('conversation_events')->where('event_type', 'cart_add_succeeded')->first();
        DB::statement('SET search_path TO public');

        $this->assertNotNull($event);
        $this->assertNull(json_decode($event->payload, true)['variation_id']);
    }

    public function test_a_conversation_id_belonging_to_a_different_chatbot_is_rejected(): void
    {
        ['chatbotId' => $chatbotIdA, 'schema' => $schema] = $this->makeChatbotWithConversation();
        ['conversationId' => $conversationIdB] = $this->makeChatbotWithConversation();

        $response = $this->postJson('/api/v1/chat/cart-event', [
            'chatbot_id' => $chatbotIdA,
            'conversation_id' => $conversationIdB, // belongs to the OTHER chatbot's tenant/schema
            'product_id' => 12,
        ]);

        // Chatbot A's own schema is active by the time the conversation
        // lookup runs, so a conversation id that only exists in a
        // different tenant's schema is simply not found there.
        $response->assertStatus(404);

        DB::statement("SET search_path TO {$schema}, public");
        $count = DB::table('conversation_events')->where('event_type', 'cart_add_succeeded')->count();
        DB::statement('SET search_path TO public');
        $this->assertEquals(0, $count);
    }

    public function test_missing_product_id_is_rejected(): void
    {
        ['chatbotId' => $chatbotId, 'conversationId' => $conversationId] = $this->makeChatbotWithConversation();

        $response = $this->postJson('/api/v1/chat/cart-event', [
            'chatbot_id' => $chatbotId,
            'conversation_id' => $conversationId,
        ]);

        $response->assertStatus(422);
    }

    public function test_nonexistent_conversation_id_is_rejected(): void
    {
        ['chatbotId' => $chatbotId] = $this->makeChatbotWithConversation();

        $response = $this->postJson('/api/v1/chat/cart-event', [
            'chatbot_id' => $chatbotId,
            'conversation_id' => (string) Str::uuid(),
            'product_id' => 12,
        ]);

        $response->assertStatus(404);
    }
}
