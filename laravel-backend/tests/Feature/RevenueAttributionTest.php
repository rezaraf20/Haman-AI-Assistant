<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Tenant;
use App\Models\Plan;
use App\Services\TenantService;
use App\Services\SyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * doc-04's revenue-attribution prerequisite: SyncService::recordOrder()
 * (reached via processWebhook()'s 'order.placed' event, fired by
 * Hamman_Sync_Manager::on_order_placed() on the plugin side) is the
 * critical trust boundary — conversation_id arrives from a browser cookie
 * a customer's own machine sent, so it must never be attached to an order
 * without being re-validated server-side against this chatbot's OWN
 * conversations table. Tested directly against the service (not the HTTP
 * layer) since the HMAC-signed /sync/webhook route's own auth is a
 * separate, already-covered concern from this method's actual logic —
 * search_path is set manually here the same way that route's own
 * tenant-resolving middleware would have already done it in production.
 */
class RevenueAttributionTest extends TestCase
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
            'id' => $conversationId, 'chatbot_id' => $chatbotId, 'session_id' => 'sess-order-1',
            'status' => 'active', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement('SET search_path TO public');

        DB::table('chatbot_index')->insert([
            'chatbot_id' => $chatbotId, 'tenant_id' => $tenant->id, 'schema_name' => $schema,
            'is_active' => true, 'name' => 'Test Bot', 'primary_domain' => null,
        ]);

        return compact('chatbotId', 'conversationId', 'schema', 'tenant');
    }

    public function test_a_real_order_is_stored_with_its_own_conversation_id(): void
    {
        ['chatbotId' => $chatbotId, 'conversationId' => $conversationId, 'schema' => $schema] = $this->makeChatbotWithConversation();

        DB::statement("SET search_path TO {$schema}, public");
        app(SyncService::class)->processWebhook([
            'event' => 'order.placed',
            'chatbot_id' => $chatbotId,
            'data' => [
                'order_id' => 5001, 'conversation_id' => $conversationId,
                'total' => 250000, 'currency' => 'IRT', 'status' => 'processing',
                'line_items' => [['product_id' => 12, 'quantity' => 1, 'total' => 250000]],
            ],
        ], $schema);

        $order = DB::table('orders')->where('chatbot_id', $chatbotId)->where('woo_order_id', 5001)->first();
        DB::statement('SET search_path TO public');

        $this->assertNotNull($order);
        $this->assertEquals($conversationId, $order->conversation_id);
        $this->assertEquals(250000, (float) $order->total);
        $lineItems = json_decode($order->line_items, true);
        $this->assertEquals(12, $lineItems[0]['product_id']);
    }

    public function test_a_conversation_id_belonging_to_a_different_chatbot_is_never_attached(): void
    {
        ['chatbotId' => $chatbotId, 'schema' => $schema] = $this->makeChatbotWithConversation();
        // A real UUID, but from a completely different (nonexistent-in-this-
        // schema) conversation — simulating a stale/manipulated cookie.
        $foreignConversationId = (string) Str::uuid();

        DB::statement("SET search_path TO {$schema}, public");
        app(SyncService::class)->processWebhook([
            'event' => 'order.placed',
            'chatbot_id' => $chatbotId,
            'data' => [
                'order_id' => 5002, 'conversation_id' => $foreignConversationId,
                'total' => 100000, 'currency' => 'IRT', 'status' => 'processing',
                'line_items' => [],
            ],
        ], $schema);

        $order = DB::table('orders')->where('chatbot_id', $chatbotId)->where('woo_order_id', 5002)->first();
        DB::statement('SET search_path TO public');

        $this->assertNotNull($order);
        $this->assertNull($order->conversation_id, 'A conversation_id that does not actually belong to this chatbot must never be attached to the order.');
    }

    public function test_a_malformed_conversation_id_is_dropped_not_crashed_on(): void
    {
        ['chatbotId' => $chatbotId, 'schema' => $schema] = $this->makeChatbotWithConversation();

        DB::statement("SET search_path TO {$schema}, public");
        app(SyncService::class)->processWebhook([
            'event' => 'order.placed',
            'chatbot_id' => $chatbotId,
            'data' => [
                'order_id' => 5003, 'conversation_id' => "not-a-uuid'; DROP TABLE orders; --",
                'total' => 50000, 'currency' => 'IRT', 'status' => 'processing', 'line_items' => [],
            ],
        ], $schema);

        $order = DB::table('orders')->where('chatbot_id', $chatbotId)->where('woo_order_id', 5003)->first();
        DB::statement('SET search_path TO public');

        $this->assertNotNull($order);
        $this->assertNull($order->conversation_id);
    }

    public function test_the_same_order_reported_twice_does_not_duplicate_or_change_its_id(): void
    {
        ['chatbotId' => $chatbotId, 'conversationId' => $conversationId, 'schema' => $schema] = $this->makeChatbotWithConversation();
        $svc = app(SyncService::class);
        $payload = [
            'event' => 'order.placed', 'chatbot_id' => $chatbotId,
            'data' => ['order_id' => 5004, 'conversation_id' => $conversationId, 'total' => 90000, 'currency' => 'IRT', 'status' => 'pending', 'line_items' => []],
        ];

        DB::statement("SET search_path TO {$schema}, public");
        $svc->processWebhook($payload, $schema);
        $firstId = DB::table('orders')->where('chatbot_id', $chatbotId)->where('woo_order_id', 5004)->value('id');

        // WooCommerce's woocommerce_thankyou can fire again on a page
        // refresh — the status changing to "completed" simulates that
        // repeat call arriving with updated order data.
        $payload['data']['status'] = 'completed';
        $svc->processWebhook($payload, $schema);

        $rows = DB::table('orders')->where('chatbot_id', $chatbotId)->where('woo_order_id', 5004)->get();
        DB::statement('SET search_path TO public');

        $this->assertCount(1, $rows, 'A repeat order.placed webhook must update the existing row, never insert a duplicate.');
        $this->assertEquals($firstId, $rows[0]->id, 'The primary key must never change on a repeat report of the same order.');
        $this->assertEquals('completed', $rows[0]->status);
    }

    public function test_missing_order_id_is_ignored_without_crashing(): void
    {
        ['chatbotId' => $chatbotId, 'schema' => $schema] = $this->makeChatbotWithConversation();

        DB::statement("SET search_path TO {$schema}, public");
        app(SyncService::class)->processWebhook([
            'event' => 'order.placed', 'chatbot_id' => $chatbotId,
            'data' => ['total' => 1000, 'currency' => 'IRT', 'line_items' => []],
        ], $schema);

        $count = DB::table('orders')->where('chatbot_id', $chatbotId)->count();
        DB::statement('SET search_path TO public');
        $this->assertEquals(0, $count);
    }
}
