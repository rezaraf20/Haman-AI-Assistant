<?php
namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\Tenant\Chatbot;
use App\Services\TenantService;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Chatbot::effectiveTools() is the one gate every consumer of enabled_tools
 * must go through (see its own docblock). Three states matter, and they must
 * not collapse into each other:
 *   - NULL  (never configured) -> the plan's allowed tools intersected with
 *     each tool's own default_enabled flag (ChatbotTools::defaultEnabledNames()).
 *   - []    (deliberately empty) -> always nothing, regardless of plan or
 *     defaults — see 2026_10_01_000001_make_chatbot_enabled_tools_nullable.
 *   - [...] (an explicit list) -> that list intersected with the plan's
 *     allowed tools, ignoring default_enabled entirely.
 */
class ChatbotEffectiveToolsTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenantAndChatbot(array $allowedTools, ?array $enabledTools): array
    {
        $plan = Plan::create([
            'name' => 'Test Plan', 'slug' => 'test-' . Str::random(8),
            'price_monthly' => 0, 'max_chatbots' => 1, 'max_tokens_monthly' => 1000000,
            'is_active' => true, 'sort_order' => 0,
            'allowed_tools' => $allowedTools,
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
            'is_active' => true, 'language' => 'fa',
            'enabled_tools' => $enabledTools === null ? null : json_encode($enabledTools),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $chatbot = Chatbot::find($chatbotId);
        DB::statement('SET search_path TO public');

        return [$tenant->fresh('plan'), $chatbot];
    }

    public function test_null_enabled_tools_falls_back_to_plan_allowed_intersected_with_defaults(): void
    {
        [$tenant, $chatbot] = $this->makeTenantAndChatbot(
            allowedTools: ['search_products', 'add_to_cart', 'get_order_status'],
            enabledTools: null,
        );

        // search_products and add_to_cart default on; get_order_status
        // defaults off even though the plan allows it.
        $this->assertEqualsCanonicalizing(['search_products', 'add_to_cart'], $chatbot->effectiveTools($tenant));
    }

    public function test_empty_array_means_deliberately_nothing_regardless_of_plan_or_defaults(): void
    {
        [$tenant, $chatbot] = $this->makeTenantAndChatbot(
            allowedTools: ['search_products', 'add_to_cart'],
            enabledTools: [],
        );

        $this->assertSame([], $chatbot->effectiveTools($tenant));
    }

    public function test_an_explicit_list_ignores_default_enabled_and_just_intersects_with_the_plan(): void
    {
        // get_order_status defaults off, but an explicit selection including
        // it must still work once the plan allows it — defaults only apply
        // to NULL, never to a real list.
        [$tenant, $chatbot] = $this->makeTenantAndChatbot(
            allowedTools: ['search_products', 'get_order_status', 'add_to_cart'],
            enabledTools: ['get_order_status', 'add_to_cart'],
        );

        $this->assertEqualsCanonicalizing(['get_order_status', 'add_to_cart'], $chatbot->effectiveTools($tenant));
    }

    public function test_an_explicit_list_is_still_capped_by_the_plan(): void
    {
        [$tenant, $chatbot] = $this->makeTenantAndChatbot(
            allowedTools: ['search_products'],
            enabledTools: ['search_products', 'add_to_cart'],
        );

        $this->assertSame(['search_products'], $chatbot->effectiveTools($tenant));
    }

    public function test_default_enabled_is_admin_editable_not_hardcoded(): void
    {
        Settings::set('tools.default_enabled.get_order_status', true);

        [$tenant, $chatbot] = $this->makeTenantAndChatbot(
            allowedTools: ['get_order_status'],
            enabledTools: null,
        );

        $this->assertSame(['get_order_status'], $chatbot->effectiveTools($tenant),
            'Raising tools.default_enabled.get_order_status from the panel must change NULL-chatbot behavior without a deploy.');
    }

    public function test_no_tenant_in_context_yields_no_tools(): void
    {
        [, $chatbot] = $this->makeTenantAndChatbot(
            allowedTools: ['search_products'],
            enabledTools: null,
        );

        $this->assertSame([], $chatbot->effectiveTools(null),
            'Without a tenant/plan to read allowed_tools from, nothing can be allowed.');
    }
}
