<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\{Tenant, Plan, PlatformSetting, OrderStatusOtp, WalletTransaction};
use App\Services\TenantService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * get_order_status (doc-04). The feature exists at all only because the
 * merchant pays for every SMS it sends, so the tests that matter most are
 * the ones proving no message goes out: to a number with no orders here,
 * past the caps, or when the model (rather than a human) would like one.
 *
 * Registration-from-chat was explicitly NOT built — anyone can type any
 * number into a public widget, and a bot that texts whatever it is given
 * is a harassment tool the shop pays for. The order-existence check below
 * is what makes an OTP flow acceptable here at all.
 */
class OrderStatusTest extends TestCase
{
    use RefreshDatabase;

    private const DOMAIN = 'example.test';
    private const ORIGIN = ['Origin' => 'https://example.test'];
    private const PHONE = '09121234567';
    private const SMS_URL = 'rest.payamak-panel.com/*';
    private const LIVE_URL = 'https://example.test/wp-json/hamman/v1/live-query';

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
            'wallet_balance_toman' => 100000,
        ]);
        $schema = 'tenant_' . str_replace('-', '', $tenant->id);
        $tenant->update(['schema_name' => $schema]);
        app(TenantService::class)->createSchema($schema);

        $chatbotId = (string) Str::uuid();
        $conversationId = (string) Str::uuid();
        DB::statement("SET search_path TO {$schema}, public");
        DB::table('chatbots')->insert(array_merge([
            'id' => $chatbotId, 'name' => 'Test Bot', 'type' => 'support', 'status' => 'active',
            'is_active' => true, 'language' => 'fa',
            'enabled_tools' => json_encode(['get_order_status']),
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
        DB::table('conversations')->insert([
            'id' => $conversationId, 'chatbot_id' => $chatbotId, 'session_id' => 'sess-os-1',
            'status' => 'active', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement('SET search_path TO public');

        DB::table('chatbot_index')->insert([
            'chatbot_id' => $chatbotId, 'tenant_id' => $tenant->id, 'schema_name' => $schema,
            'is_active' => true, 'name' => 'Test Bot', 'primary_domain' => self::DOMAIN,
        ]);

        PlatformSetting::current()->update([
            'melipayamak_username' => 'u', 'melipayamak_password' => 'p',
            'melipayamak_sender' => '3000', 'sms_cost_toman' => 50,
        ]);

        return compact('chatbotId', 'conversationId', 'schema', 'tenant');
    }

    /** The store answers "yes this contact has orders" and the SMS gateway accepts. */
    private function fakeStore(bool $hasOrders = true, array $orders = null, bool $smsOk = true): void
    {
        $orders = $orders ?? [[
            'number' => '1042', 'status' => 'processing', 'date_created' => '2026-09-01',
            'tracking' => 'TRK99', 'items' => [['name' => 'Test Widget', 'quantity' => 2]],
        ]];

        Http::fake([
            self::SMS_URL => Http::response(['RetStatus' => $smsOk ? 1 : 0], 200),
            self::LIVE_URL => function ($request) use ($hasOrders, $orders) {
                $body = json_decode($request->body(), true);
                if (($body['action'] ?? null) === 'contact_has_orders') {
                    return Http::response(['found' => $hasOrders, 'count' => $hasOrders ? count($orders) : 0], 200);
                }
                if (($body['action'] ?? null) === 'get_orders_for_contact') {
                    return Http::response(['orders' => $orders], 200);
                }
                return Http::response(['error' => 'unexpected_action'], 400);
            },
        ]);
    }

    private function requestCode(string $chatbotId, string $conversationId, string $phone = self::PHONE)
    {
        return $this->postJson('/api/v1/chat/order-status/request-code', [
            'chatbot_id' => $chatbotId, 'conversation_id' => $conversationId, 'contact' => $phone,
        ], self::ORIGIN);
    }

    private function assertNoSmsSent(string $because): void
    {
        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), 'payamak-panel.com');
        });
        $this->assertTrue(true, $because);
    }

    // ── The whole point: a number with no orders here costs nothing ─────

    public function test_a_number_with_no_orders_at_this_store_never_receives_an_sms(): void
    {
        ['chatbotId' => $c, 'conversationId' => $v, 'tenant' => $tenant] = $this->makeChatbot();
        $this->fakeStore(hasOrders: false);

        $response = $this->requestCode($c, $v);

        $response->assertOk();
        $response->assertJsonPath('data.sent', false);
        $response->assertJsonPath('data.reason', 'no_orders');
        $this->assertNoSmsSent('A contact with no orders must never trigger a paid SMS.');

        // Nothing stored, and nothing charged.
        $this->assertEquals(0, OrderStatusOtp::count());
        $this->assertEquals(100000, $tenant->fresh()->wallet_balance_toman);
    }

    public function test_an_unreachable_store_never_sends_an_sms_either(): void
    {
        ['chatbotId' => $c, 'conversationId' => $v] = $this->makeChatbot();
        Http::fake([
            self::SMS_URL => Http::response(['RetStatus' => 1], 200),
            self::LIVE_URL => Http::response([], 500),
        ]);

        $this->requestCode($c, $v)->assertStatus(400);
        $this->assertNoSmsSent('An unreachable store must fail closed, not send blind.');
        $this->assertEquals(0, OrderStatusOtp::count());
    }

    public function test_the_tool_must_be_enabled_before_anything_happens(): void
    {
        ['chatbotId' => $c, 'conversationId' => $v] = $this->makeChatbot([
            'enabled_tools' => json_encode([]),
        ]);
        $this->fakeStore();

        $this->requestCode($c, $v)->assertStatus(403);
        $this->assertNoSmsSent('A chatbot without the tool enabled must not send.');
        Http::assertNothingSent();
    }

    public function test_a_number_that_is_not_a_valid_iranian_mobile_is_refused_before_any_lookup(): void
    {
        ['chatbotId' => $c, 'conversationId' => $v] = $this->makeChatbot();
        $this->fakeStore();

        $this->requestCode($c, $v, '12345')->assertStatus(400);
        Http::assertNothingSent();
    }

    // ── Happy path: a real order's number ───────────────────────────────

    public function test_a_real_orders_number_gets_a_code_and_the_merchant_is_charged(): void
    {
        ['chatbotId' => $c, 'conversationId' => $v, 'tenant' => $tenant] = $this->makeChatbot();
        $this->fakeStore();

        $response = $this->requestCode($c, $v);

        $response->assertOk();
        $response->assertJsonPath('data.sent', true);
        // Never echoes the full number back to the browser.
        $response->assertJsonPath('data.contact', '0912***4567');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'payamak-panel.com'));

        $otp = OrderStatusOtp::first();
        $this->assertNotNull($otp);
        $this->assertEquals(self::PHONE, $otp->contact);
        $this->assertEquals(0, $otp->attempts);
        // The code is hashed at rest, not stored in the clear.
        $this->assertNotEmpty($otp->code_hash);
        $this->assertStringStartsWith('$2y$', $otp->code_hash);

        // Charged to the tenant, and visible in their own wallet ledger.
        $this->assertEquals(100000 - 50, $tenant->fresh()->wallet_balance_toman);
        $txn = WalletTransaction::where('tenant_id', $tenant->id)->where('type', 'sms_otp')->first();
        $this->assertNotNull($txn);
        $this->assertEquals(-50, $txn->amount_toman);
        // The ledger records a masked number, never the full one.
        $this->assertStringContainsString('0912***4567', $txn->description);
        $this->assertStringNotContainsString(self::PHONE, $txn->description);
    }

    public function test_nothing_is_charged_when_the_sms_gateway_fails(): void
    {
        ['chatbotId' => $c, 'conversationId' => $v, 'tenant' => $tenant] = $this->makeChatbot();
        $this->fakeStore(smsOk: false);

        $this->requestCode($c, $v)->assertStatus(400);

        $this->assertEquals(0, OrderStatusOtp::count(), 'No code row when the message never left.');
        $this->assertEquals(100000, $tenant->fresh()->wallet_balance_toman);
    }

    // ── Caps, tested by hammering ───────────────────────────────────────

    public function test_a_fourth_code_request_for_the_same_number_within_an_hour_is_refused(): void
    {
        ['chatbotId' => $c, 'conversationId' => $v] = $this->makeChatbot();
        $this->fakeStore();

        for ($i = 0; $i < 3; $i++) {
            $this->requestCode($c, $v)->assertOk();
        }
        $this->requestCode($c, $v)->assertStatus(429);

        $this->assertEquals(3, OrderStatusOtp::count(), 'Only three messages were ever paid for.');
    }

    public function test_the_per_number_cap_cannot_be_dodged_by_retyping_the_number_differently(): void
    {
        ['chatbotId' => $c, 'conversationId' => $v] = $this->makeChatbot();
        $this->fakeStore();

        // Same human, four spellings of one number.
        $this->requestCode($c, $v, '09121234567')->assertOk();
        $this->requestCode($c, $v, '+989121234567')->assertOk();
        $this->requestCode($c, $v, '9121234567')->assertOk();
        $this->requestCode($c, $v, '00989121234567')->assertStatus(429);

        $this->assertEquals(3, OrderStatusOtp::count());
    }

    public function test_an_expired_hourly_window_lets_the_same_number_try_again(): void
    {
        ['chatbotId' => $c, 'conversationId' => $v] = $this->makeChatbot();
        $this->fakeStore();

        for ($i = 0; $i < 3; $i++) {
            $this->requestCode($c, $v)->assertOk();
        }
        OrderStatusOtp::query()->update(['created_at' => now()->subHours(2)]);

        $this->requestCode($c, $v)->assertOk();
    }

    // ── Verification: 5 minutes, 3 attempts ─────────────────────────────

    private function sendAndCaptureCode(string $chatbotId, string $conversationId): string
    {
        $this->requestCode($chatbotId, $conversationId)->assertOk();
        // The real code is only ever in the SMS, so read it from the
        // outbound request the gateway received.
        $code = null;
        Http::assertSent(function ($request) use (&$code) {
            if (str_contains($request->url(), 'payamak-panel.com')) {
                $code = $request['text'];
                return true;
            }
            return false;
        });
        return (string) $code;
    }

    private function verify(string $chatbotId, string $conversationId, string $code)
    {
        return $this->postJson('/api/v1/chat/order-status/verify', [
            'chatbot_id' => $chatbotId, 'conversation_id' => $conversationId,
            'contact' => self::PHONE, 'code' => $code,
        ], self::ORIGIN);
    }

    public function test_the_correct_code_returns_only_status_tracking_and_items(): void
    {
        ['chatbotId' => $c, 'conversationId' => $v, 'schema' => $schema] = $this->makeChatbot();
        $this->fakeStore();
        $code = $this->sendAndCaptureCode($c, $v);

        $response = $this->verify($c, $v, $code);

        $response->assertOk();
        $response->assertJsonPath('data.orders.0.number', '1042');
        $response->assertJsonPath('data.orders.0.status', 'processing');
        $response->assertJsonPath('data.orders.0.tracking', 'TRK99');
        $response->assertJsonPath('data.orders.0.items.0.name', 'Test Widget');

        // The response shape is pinned: no address, no payment, no contact.
        $order = $response->json('data.orders.0');
        $this->assertEqualsCanonicalizing(
            ['number', 'status', 'date_created', 'tracking', 'items'],
            array_keys($order),
            'An order returned to the browser must carry nothing but these fields.'
        );

        // And the event the task asked for.
        DB::statement("SET search_path TO {$schema}, public");
        $event = DB::table('conversation_events')->where('event_type', 'order_status_viewed')->first();
        DB::statement('SET search_path TO public');
        $this->assertNotNull($event);
        $payload = json_decode($event->payload, true);
        $this->assertEquals(1, $payload['order_count']);
        $this->assertEquals(['1042'], $payload['order_numbers']);
        // The event must not record the phone number.
        $this->assertStringNotContainsString(self::PHONE, $event->payload);
    }

    public function test_even_if_the_store_returns_an_address_it_never_reaches_the_browser(): void
    {
        ['chatbotId' => $c, 'conversationId' => $v] = $this->makeChatbot();
        $this->fakeStore(orders: [[
            'number' => '1042', 'status' => 'processing', 'date_created' => '2026-09-01',
            'tracking' => null, 'items' => [['name' => 'Widget', 'quantity' => 1]],
            // An older/rogue plugin adding fields it should not.
            'billing_address' => 'Tehran, Valiasr St, No 5',
            'payment_method' => 'zarinpal', 'transaction_id' => 'TXN-SECRET-1',
        ]]);
        $code = $this->sendAndCaptureCode($c, $v);

        $response = $this->verify($c, $v, $code);

        $response->assertOk();
        $body = $response->getContent();
        $this->assertStringNotContainsString('Valiasr', $body);
        $this->assertStringNotContainsString('TXN-SECRET-1', $body);
        $this->assertStringNotContainsString('zarinpal', $body);
    }

    public function test_a_wrong_code_is_rejected_and_the_third_wrong_attempt_locks_it(): void
    {
        ['chatbotId' => $c, 'conversationId' => $v] = $this->makeChatbot();
        $this->fakeStore();
        $this->sendAndCaptureCode($c, $v);

        $this->verify($c, $v, '00000')->assertStatus(400);
        $this->verify($c, $v, '00001')->assertStatus(400);
        $this->verify($c, $v, '00002')->assertStatus(400);
        // Fourth try is refused outright, even if it were correct.
        $this->verify($c, $v, '00003')->assertStatus(429);

        $this->assertEquals(3, OrderStatusOtp::first()->attempts);
    }

    public function test_an_expired_code_is_refused(): void
    {
        ['chatbotId' => $c, 'conversationId' => $v] = $this->makeChatbot();
        $this->fakeStore();
        $code = $this->sendAndCaptureCode($c, $v);

        OrderStatusOtp::query()->update(['expires_at' => now()->subMinute()]);

        $this->verify($c, $v, $code)->assertStatus(400);
    }

    public function test_a_code_cannot_be_used_twice(): void
    {
        ['chatbotId' => $c, 'conversationId' => $v] = $this->makeChatbot();
        $this->fakeStore();
        $code = $this->sendAndCaptureCode($c, $v);

        $this->verify($c, $v, $code)->assertOk();
        $this->verify($c, $v, $code)->assertStatus(400);
    }

    public function test_a_code_from_one_chatbot_cannot_be_redeemed_at_another(): void
    {
        ['chatbotId' => $cA, 'conversationId' => $vA] = $this->makeChatbot();
        $this->fakeStore();
        $code = $this->sendAndCaptureCode($cA, $vA);

        ['chatbotId' => $cB, 'conversationId' => $vB] = $this->makeChatbot();

        $this->postJson('/api/v1/chat/order-status/verify', [
            'chatbot_id' => $cB, 'conversation_id' => $vB,
            'contact' => self::PHONE, 'code' => $code,
        ], self::ORIGIN)->assertStatus(400);
    }

    public function test_verifying_without_ever_requesting_a_code_is_refused(): void
    {
        ['chatbotId' => $c, 'conversationId' => $v] = $this->makeChatbot();
        $this->fakeStore();

        $this->verify($c, $v, '12345')->assertStatus(400);
    }
}
