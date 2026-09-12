<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\{User, Tenant, Plan};
use App\Services\TenantService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The merchant-facing half of create_payment_link (doc-04): "a view of
 * orders created from chat, with payment status". This renders the real
 * Filament page, not just the query behind it — the page reads a tenant
 * schema via SET search_path, so a test that only called getOrders()
 * would miss whether the page itself survives the panel's own lifecycle.
 *
 * Two things matter beyond "it renders": it must show ONLY bot-created
 * orders (a normal checkout that merely got cookie-attributed by the
 * earlier revenue-attribution work is a different thing), and it must
 * never leak another tenant's orders.
 */
class ChatOrdersPageTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenantWithUser(): array
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

        $user = User::create([
            'tenant_id' => $tenant->id,
            'email' => Str::random(10) . '@example.test',
            'password' => bcrypt('irrelevant'),
            'password_hash' => bcrypt('irrelevant'),
            'name' => 'Test Customer',
            'role' => 'owner',
            'email_verified_at' => now(),
        ]);

        $chatbotId = (string) Str::uuid();
        DB::statement("SET search_path TO {$schema}, public");
        DB::table('chatbots')->insert([
            'id' => $chatbotId, 'name' => 'Test Bot', 'type' => 'support', 'status' => 'active',
            'is_active' => true, 'language' => 'fa', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement('SET search_path TO public');

        return compact('tenant', 'schema', 'user', 'chatbotId');
    }

    private function addOrder(string $schema, string $chatbotId, int $wooId, float $total, string $status, bool $byBot): void
    {
        DB::statement("SET search_path TO {$schema}, public");
        DB::table('orders')->insert([
            'id' => (string) Str::uuid(), 'chatbot_id' => $chatbotId, 'conversation_id' => null,
            'woo_order_id' => $wooId, 'total' => $total, 'currency' => 'IRT',
            'status' => $status, 'line_items' => json_encode([]), 'created_by_bot' => $byBot,
            'created_at' => now(),
        ]);
        DB::statement('SET search_path TO public');
    }

    public function test_the_page_lists_bot_created_orders_with_their_payment_status(): void
    {
        ['user' => $user, 'schema' => $schema, 'chatbotId' => $chatbotId] = $this->makeTenantWithUser();
        $this->addOrder($schema, $chatbotId, 5001, 180000, 'pending', true);
        $this->addOrder($schema, $chatbotId, 5002, 90000, 'completed', true);
        $this->addOrder($schema, $chatbotId, 5003, 45000, 'cancelled', true);

        $response = $this->actingAs($user, 'web')->get('/portal/chat-orders');

        $response->assertOk();
        $response->assertSee('#5001');
        $response->assertSee('#5002');
        $response->assertSee('#5003');
        $response->assertSee(number_format(180000));
        // The translated payment status, not the raw WooCommerce slug.
        $response->assertSee(__('chat_orders.status_pending'));
        $response->assertSee(__('chat_orders.status_completed'));
        $response->assertSee(__('chat_orders.status_cancelled'));
    }

    public function test_an_order_not_created_by_the_bot_is_never_listed(): void
    {
        ['user' => $user, 'schema' => $schema, 'chatbotId' => $chatbotId] = $this->makeTenantWithUser();
        // A normal checkout the widget merely got attribution credit for.
        $this->addOrder($schema, $chatbotId, 7777, 250000, 'completed', false);
        $this->addOrder($schema, $chatbotId, 5001, 180000, 'pending', true);

        $response = $this->actingAs($user, 'web')->get('/portal/chat-orders');

        $response->assertOk();
        $response->assertSee('#5001');
        $response->assertDontSee('#7777');
    }

    public function test_a_tenant_never_sees_another_tenants_chat_orders(): void
    {
        ['user' => $userA, 'schema' => $schemaA, 'chatbotId' => $botA] = $this->makeTenantWithUser();
        ['schema' => $schemaB, 'chatbotId' => $botB] = $this->makeTenantWithUser();

        $this->addOrder($schemaA, $botA, 1111, 10000, 'pending', true);
        $this->addOrder($schemaB, $botB, 2222, 20000, 'pending', true);

        $response = $this->actingAs($userA, 'web')->get('/portal/chat-orders');

        $response->assertOk();
        $response->assertSee('#1111');
        $response->assertDontSee('#2222');
    }

    public function test_a_tenant_with_no_chat_orders_sees_the_empty_state(): void
    {
        ['user' => $user] = $this->makeTenantWithUser();

        $response = $this->actingAs($user, 'web')->get('/portal/chat-orders');

        $response->assertOk();
        $response->assertSee(__('chat_orders.empty'));
    }

    public function test_a_guest_cannot_reach_the_page(): void
    {
        $response = $this->get('/portal/chat-orders');

        $response->assertRedirect();
    }
}
