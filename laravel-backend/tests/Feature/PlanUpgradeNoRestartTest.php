<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Tenant;
use App\Models\Plan;
use App\Models\Tenant\Chatbot;
use App\Services\TenantService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Acceptance criterion 3: upgrading a tenant's plan must make a
 * newly-allowed tool available on the very next request — no cache to
 * bust, no queue job, no container restart. Chatbot::effectiveTools() is
 * deliberately uncached (see its own docblock) specifically for this; this
 * test proves it end to end, through the real HTTP endpoint a merchant's
 * widget actually calls, not just the model method in isolation — the
 * exact scenario from the business-model spec: "show add_to_cart becomes
 * available immediately, with no service restart."
 *
 * Reuses create_payment_link / ChatController::createPaymentLink() as the
 * concrete tool (same gate add_to_cart goes through), since it already has
 * a proven, fully-set-up test path in PaymentLinkTest.
 */
class PlanUpgradeNoRestartTest extends TestCase
{
    use RefreshDatabase;

    private const DOMAIN = 'shop.example.com';
    private const ORIGIN = ['Origin' => 'https://shop.example.com'];

    private function fakeLiveQuery(int $previewTotal = 90000, int $orderId = 901): void
    {
        $nextOrderId = $orderId;
        Http::fake(function ($request) use ($previewTotal, &$nextOrderId) {
            $body = json_decode($request->body(), true);
            if (($body['action'] ?? null) === 'preview_order') {
                return Http::response([
                    'items' => array_map(fn ($i) => ['product_id' => $i['product_id'], 'name' => 'Widget', 'quantity' => $i['quantity'] ?? 1, 'line_total' => $previewTotal], $body['items']),
                    'total' => $previewTotal, 'currency' => 'IRT',
                ], 200);
            }
            if (($body['action'] ?? null) === 'create_draft_order') {
                $orderId = $nextOrderId++;
                return Http::response([
                    'order_id' => $orderId,
                    'key' => 'wc_order_' . Str::random(16),
                    'order_pay_url' => "https://" . self::DOMAIN . "/checkout/order-pay/{$orderId}/?pay_for_order=true&key=wc_order_secret_abc123",
                    'total' => $previewTotal, 'currency' => 'IRT',
                ], 200);
            }
            return Http::response(['error' => 'unexpected_action'], 400);
        });
    }

    public function test_upgrading_the_plan_unlocks_a_tool_on_the_very_next_request_no_restart(): void
    {
        // Restrictive: the merchant already selected create_payment_link on
        // their chatbot, but their current plan doesn't allow it — the
        // real, common case (effectiveTools intersects both).
        $restrictivePlan = Plan::create([
            'name' => 'Restrictive', 'slug' => 'restrictive-' . Str::random(8),
            'price_monthly' => 0, 'max_chatbots' => 1, 'max_tokens_monthly' => 1000000,
            'is_active' => true, 'sort_order' => 0,
            'allowed_tools' => ['search_products'],
        ]);
        $generousPlan = Plan::create([
            'name' => 'Generous', 'slug' => 'generous-' . Str::random(8),
            'price_monthly' => 990000, 'max_chatbots' => 3, 'max_tokens_monthly' => 3000000,
            'is_active' => true, 'sort_order' => 1,
            'allowed_tools' => ['search_products', 'create_payment_link'],
        ]);

        $tenant = Tenant::create([
            'slug' => 'upgrade-' . Str::random(8), 'name' => 'Upgrade Test Tenant',
            'email' => Str::random(12) . '@shop.example.com', 'plan_id' => $restrictivePlan->id,
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
            'id' => $conversationId, 'chatbot_id' => $chatbotId, 'session_id' => 'sess-upgrade-1',
            'status' => 'active', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement('SET search_path TO public');
        DB::table('chatbot_index')->insert([
            'chatbot_id' => $chatbotId, 'tenant_id' => $tenant->id, 'schema_name' => $schema,
            'is_active' => true, 'name' => 'Test Bot', 'primary_domain' => self::DOMAIN,
        ]);

        $this->fakeLiveQuery();
        $payload = [
            'chatbot_id' => $chatbotId,
            'conversation_id' => $conversationId,
            'items' => [['product_id' => 12, 'quantity' => 2]],
        ];

        // Before: the plan doesn't allow it, regardless of the merchant's
        // own selection — blocked by the tool gate.
        $before = $this->postJson('/api/v1/chat/payment-link', $payload, self::ORIGIN);
        $before->assertStatus(403);

        DB::statement("SET search_path TO {$schema}, public");
        $chatbotBefore = Chatbot::find($chatbotId);
        DB::statement('SET search_path TO public');
        $this->assertSame([], $chatbotBefore->effectiveTools($tenant->fresh('plan')));

        // The upgrade itself: exactly what ChangePlan's upgrade action does
        // — a plain column update. No cache clear, no queue job, no
        // artisan command, no container restart — nothing else happens
        // between this line and the next request below.
        $tenant->update(['plan_id' => $generousPlan->id]);

        // After, same test/process, same running PHP — proving no restart
        // was needed for this to take effect.
        DB::statement("SET search_path TO {$schema}, public");
        $chatbotAfter = Chatbot::find($chatbotId);
        DB::statement('SET search_path TO public');
        $this->assertSame(['create_payment_link'], $chatbotAfter->effectiveTools($tenant->fresh('plan')),
            'effectiveTools() must reflect the new plan on the very next call, with nothing else changed.');

        $after = $this->postJson('/api/v1/chat/payment-link', $payload, self::ORIGIN);
        $after->assertOk();
        $after->assertJsonPath('data.order_id', 901);
    }
}
