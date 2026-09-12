<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Tenant;
use App\Models\Plan;
use App\Services\TenantService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * ChatController::createPaymentLink() — every security rule the task's own
 * "don't build this without X" list required, verified as actually
 * enforced (not merely present as a comment): off by default until
 * enabled_tools contains create_payment_link AND a real
 * max_payment_link_amount is configured; a per-conversation cap; a
 * per-IP-per-day cap; a fresh live total re-checked against the cap right
 * before creating anything; and the order_key/order_pay_url is never
 * persisted anywhere — only order_id ever reaches the orders table or the
 * payment_link_created event payload. The model-callable Python tool
 * (create_payment_link) never reaches this endpoint at all — it only
 * previews; this is the one and only place a real WooCommerce draft order
 * is ever created, reached solely by a genuine widget "Confirm & Pay"
 * click. See PaymentLinkService for the HTTP calls this endpoint makes to
 * the WordPress plugin's live-query endpoint, faked here via Http::fake().
 */
class PaymentLinkTest extends TestCase
{
    use RefreshDatabase;

    private const DOMAIN = 'example.test';
    private const ORIGIN = ['Origin' => 'https://example.test'];

    private function makeChatbot(array $overrides = []): array
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
            'settings' => ['webhook_secret' => Str::random(32)],
        ]);
        $schema = 'tenant_' . str_replace('-', '', $tenant->id);
        $tenant->update(['schema_name' => $schema]);
        app(TenantService::class)->createSchema($schema);

        $chatbotId = (string) Str::uuid();
        $conversationId = (string) Str::uuid();
        DB::statement("SET search_path TO {$schema}, public");
        DB::table('chatbots')->insert(array_merge([
            'id' => $chatbotId, 'name' => 'Test Bot', 'type' => 'support', 'status' => 'active',
            'is_active' => true, 'language' => 'en',
            'enabled_tools' => json_encode(['create_payment_link']),
            'max_payment_link_amount' => 1000000,
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
        DB::table('conversations')->insert([
            'id' => $conversationId, 'chatbot_id' => $chatbotId, 'session_id' => 'sess-pay-1',
            'status' => 'active', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement('SET search_path TO public');

        DB::table('chatbot_index')->insert([
            'chatbot_id' => $chatbotId, 'tenant_id' => $tenant->id, 'schema_name' => $schema,
            'is_active' => true, 'name' => 'Test Bot', 'primary_domain' => self::DOMAIN,
        ]);

        return compact('chatbotId', 'conversationId', 'schema', 'tenant');
    }

    private function newConversation(string $schema, string $chatbotId): string
    {
        $conversationId = (string) Str::uuid();
        DB::statement("SET search_path TO {$schema}, public");
        DB::table('conversations')->insert([
            'id' => $conversationId, 'chatbot_id' => $chatbotId, 'session_id' => 'sess-pay-' . Str::random(6),
            'status' => 'active', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement('SET search_path TO public');
        return $conversationId;
    }

    private function fakeLiveQuery(int $previewTotal = 90000, ?string $currency = 'IRT', int $orderId = 501): void
    {
        // WooCommerce allocates a fresh order id for every draft order, so
        // the fake must too — orders has UNIQUE(chatbot_id, woo_order_id),
        // and a fake that reuses one id would fail for a reason no real
        // store can ever hit (caught exactly that way the first time).
        $nextOrderId = $orderId;
        Http::fake(function ($request) use ($previewTotal, $currency, &$nextOrderId) {
            $body = json_decode($request->body(), true);
            if (($body['action'] ?? null) === 'preview_order') {
                return Http::response([
                    'items' => array_map(fn ($i) => ['product_id' => $i['product_id'], 'name' => 'Widget', 'quantity' => $i['quantity'] ?? 1, 'line_total' => $previewTotal], $body['items']),
                    'total' => $previewTotal, 'currency' => $currency,
                ], 200);
            }
            if (($body['action'] ?? null) === 'create_draft_order') {
                $orderId = $nextOrderId++;
                return Http::response([
                    'order_id' => $orderId,
                    'key' => 'wc_order_' . Str::random(16),
                    'order_pay_url' => "https://" . self::DOMAIN . "/checkout/order-pay/{$orderId}/?pay_for_order=true&key=wc_order_secret_abc123",
                    'total' => $previewTotal, 'currency' => $currency,
                ], 200);
            }
            return Http::response(['error' => 'unexpected_action'], 400);
        });
    }

    private function payload(string $chatbotId, string $conversationId): array
    {
        return [
            'chatbot_id' => $chatbotId,
            'conversation_id' => $conversationId,
            'items' => [['product_id' => 12, 'quantity' => 2]],
        ];
    }

    // ── Rule: off by default until explicitly enabled ──────────────────

    public function test_disabled_when_create_payment_link_is_not_in_enabled_tools(): void
    {
        ['chatbotId' => $chatbotId, 'conversationId' => $conversationId] = $this->makeChatbot([
            'enabled_tools' => json_encode([]),
        ]);
        $this->fakeLiveQuery();

        $response = $this->postJson('/api/v1/chat/payment-link', $this->payload($chatbotId, $conversationId), self::ORIGIN);

        $response->assertStatus(403);
        Http::assertNothingSent();
    }

    // ── Rule: a real per-chatbot amount cap must be explicitly set ─────

    public function test_disabled_when_max_payment_link_amount_is_not_configured(): void
    {
        ['chatbotId' => $chatbotId, 'conversationId' => $conversationId] = $this->makeChatbot([
            'max_payment_link_amount' => null,
        ]);
        $this->fakeLiveQuery();

        $response = $this->postJson('/api/v1/chat/payment-link', $this->payload($chatbotId, $conversationId), self::ORIGIN);

        $response->assertStatus(403);
        Http::assertNothingSent();
    }

    // ── Rule: fresh live total re-checked against the cap before anything is created ──

    public function test_an_order_exceeding_the_configured_cap_is_refused_and_nothing_is_created(): void
    {
        ['chatbotId' => $chatbotId, 'conversationId' => $conversationId, 'schema' => $schema] = $this->makeChatbot([
            'max_payment_link_amount' => 50000,
        ]);
        $this->fakeLiveQuery(previewTotal: 90000);

        $response = $this->postJson('/api/v1/chat/payment-link', $this->payload($chatbotId, $conversationId), self::ORIGIN);

        $response->assertStatus(403);
        Http::assertNotSent(function ($request) {
            $body = json_decode($request->body(), true);
            return ($body['action'] ?? null) === 'create_draft_order';
        });

        DB::statement("SET search_path TO {$schema}, public");
        $orderCount = DB::table('orders')->count();
        $eventCount = DB::table('conversation_events')->where('event_type', 'payment_link_created')->count();
        DB::statement('SET search_path TO public');
        $this->assertEquals(0, $orderCount, 'No order row should exist when the cap was exceeded.');
        $this->assertEquals(0, $eventCount);
    }

    public function test_an_order_exactly_at_the_cap_is_allowed(): void
    {
        ['chatbotId' => $chatbotId, 'conversationId' => $conversationId] = $this->makeChatbot([
            'max_payment_link_amount' => 90000,
        ]);
        $this->fakeLiveQuery(previewTotal: 90000);

        $response = $this->postJson('/api/v1/chat/payment-link', $this->payload($chatbotId, $conversationId), self::ORIGIN);

        $response->assertStatus(200);
    }

    // ── Rule: per-conversation cap ──────────────────────────────────────

    public function test_a_fourth_payment_link_in_the_same_conversation_is_rejected(): void
    {
        ['chatbotId' => $chatbotId, 'conversationId' => $conversationId] = $this->makeChatbot();
        $this->fakeLiveQuery();

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/chat/payment-link', $this->payload($chatbotId, $conversationId), self::ORIGIN)
                ->assertStatus(200);
        }

        $response = $this->postJson('/api/v1/chat/payment-link', $this->payload($chatbotId, $conversationId), self::ORIGIN);
        $response->assertStatus(429);
    }

    // ── Rule: per-IP-per-day cap ─────────────────────────────────────────

    public function test_a_sixth_payment_link_from_the_same_ip_in_one_day_is_rejected(): void
    {
        ['chatbotId' => $chatbotId, 'schema' => $schema] = $this->makeChatbot();
        $this->fakeLiveQuery();

        // A fresh conversation per call, so the per-conversation cap (3)
        // never trips before the per-IP cap (5) does — this test targets
        // the IP cap specifically, isolated from the conversation cap.
        for ($i = 0; $i < 5; $i++) {
            $conversationId = $this->newConversation($schema, $chatbotId);
            $this->postJson('/api/v1/chat/payment-link', $this->payload($chatbotId, $conversationId), self::ORIGIN)
                ->assertStatus(200);
        }

        $sixthConversation = $this->newConversation($schema, $chatbotId);
        $response = $this->postJson('/api/v1/chat/payment-link', $this->payload($chatbotId, $sixthConversation), self::ORIGIN);
        $response->assertStatus(429);
    }

    // ── Rule: the sensitive key/order_pay_url is never persisted ───────

    public function test_a_successful_order_persists_only_order_id_never_key_or_url(): void
    {
        ['chatbotId' => $chatbotId, 'conversationId' => $conversationId, 'schema' => $schema] = $this->makeChatbot();
        $this->fakeLiveQuery(previewTotal: 90000, orderId: 777);

        $response = $this->postJson('/api/v1/chat/payment-link', $this->payload($chatbotId, $conversationId), self::ORIGIN);

        $response->assertStatus(200);
        $response->assertJsonPath('data.order_id', 777);
        $this->assertStringContainsString('key=wc_order_secret_abc123', $response->json('data.order_pay_url'));

        DB::statement("SET search_path TO {$schema}, public");
        $order = DB::table('orders')->where('woo_order_id', 777)->first();
        $event = DB::table('conversation_events')->where('event_type', 'payment_link_created')->first();
        DB::statement('SET search_path TO public');

        $this->assertNotNull($order);
        $this->assertEquals(90000, (float) $order->total);
        $this->assertTrue((bool) $order->created_by_bot);
        $orderRow = (array) $order;
        foreach ($orderRow as $column => $value) {
            $this->assertStringNotContainsString('wc_order_secret_abc123', (string) $value, "orders.{$column} must never contain the order key/URL.");
        }

        $this->assertNotNull($event);
        $payload = json_decode($event->payload, true);
        $this->assertEqualsCanonicalizing(['order_id', 'total', 'currency'], array_keys($payload), 'The payment_link_created event payload must contain only order_id/total/currency — never key or order_pay_url.');
        $this->assertEquals(777, $payload['order_id']);
        $this->assertStringNotContainsString('wc_order_secret_abc123', json_encode($payload));
    }

    // ── Rule: a fresh live preview failure blocks creation ──────────────

    public function test_a_live_preview_failure_blocks_the_order_and_creates_nothing(): void
    {
        ['chatbotId' => $chatbotId, 'conversationId' => $conversationId, 'schema' => $schema] = $this->makeChatbot();
        Http::fake([
            'https://' . self::DOMAIN . '/wp-json/hamman/v1/live-query' => Http::response([], 500),
        ]);

        $response = $this->postJson('/api/v1/chat/payment-link', $this->payload($chatbotId, $conversationId), self::ORIGIN);

        $response->assertStatus(400);
        DB::statement("SET search_path TO {$schema}, public");
        $orderCount = DB::table('orders')->count();
        DB::statement('SET search_path TO public');
        $this->assertEquals(0, $orderCount);
    }

    public function test_no_domain_on_file_is_rejected_before_any_live_call(): void
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
            'settings' => ['webhook_secret' => Str::random(32)],
        ]);
        $schema = 'tenant_' . str_replace('-', '', $tenant->id);
        $tenant->update(['schema_name' => $schema]);
        app(TenantService::class)->createSchema($schema);

        $chatbotId = (string) Str::uuid();
        $conversationId = (string) Str::uuid();
        DB::statement("SET search_path TO {$schema}, public");
        DB::table('chatbots')->insert([
            'id' => $chatbotId, 'name' => 'Test Bot', 'type' => 'support', 'status' => 'active',
            'is_active' => true, 'language' => 'en',
            'enabled_tools' => json_encode(['create_payment_link']),
            'max_payment_link_amount' => 1000000,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('conversations')->insert([
            'id' => $conversationId, 'chatbot_id' => $chatbotId, 'session_id' => 'sess-pay-nodomain',
            'status' => 'active', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement('SET search_path TO public');
        // primary_domain left null — no store connection on file.
        DB::table('chatbot_index')->insert([
            'chatbot_id' => $chatbotId, 'tenant_id' => $tenant->id, 'schema_name' => $schema,
            'is_active' => true, 'name' => 'Test Bot', 'primary_domain' => null,
        ]);

        Http::fake();
        // No Origin-vs-stored-domain mismatch possible since primary_domain
        // is null — any Origin passes ValidateChatbotDomain, matching the
        // real-world case of a chatbot that hasn't had its domain set yet.
        $response = $this->postJson('/api/v1/chat/payment-link', $this->payload($chatbotId, $conversationId), self::ORIGIN);

        $response->assertStatus(400);
        Http::assertNothingSent();
    }

    // ── Basic input/ownership validation ────────────────────────────────

    public function test_a_conversation_id_belonging_to_a_different_chatbot_is_rejected(): void
    {
        ['chatbotId' => $chatbotIdA] = $this->makeChatbot();
        ['conversationId' => $conversationIdB] = $this->makeChatbot();
        $this->fakeLiveQuery();

        $response = $this->postJson('/api/v1/chat/payment-link', $this->payload($chatbotIdA, $conversationIdB), self::ORIGIN);

        $response->assertStatus(404);
    }

    public function test_missing_items_is_rejected(): void
    {
        ['chatbotId' => $chatbotId, 'conversationId' => $conversationId] = $this->makeChatbot();

        $response = $this->postJson('/api/v1/chat/payment-link', [
            'chatbot_id' => $chatbotId, 'conversation_id' => $conversationId,
        ], self::ORIGIN);

        $response->assertStatus(422);
    }

    public function test_more_than_ten_items_is_rejected(): void
    {
        ['chatbotId' => $chatbotId, 'conversationId' => $conversationId] = $this->makeChatbot();

        $response = $this->postJson('/api/v1/chat/payment-link', [
            'chatbot_id' => $chatbotId, 'conversation_id' => $conversationId,
            'items' => array_map(fn ($i) => ['product_id' => $i, 'quantity' => 1], range(1, 11)),
        ], self::ORIGIN);

        $response->assertStatus(422);
    }
}
