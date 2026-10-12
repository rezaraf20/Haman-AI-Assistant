<?php
namespace Tests\Feature;

use App\Filament\Customer\Pages\MyChatbots;
use App\Models\{Plan, Tenant, User};
use App\Services\TenantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Filament evaluates a table column's visible()/hidden() closure once with
 * NO record at all, independent of any row, to decide whether that column
 * belongs in the "toggle columns" menu -- not just per-row while rendering
 * actual data. The disabled_reason column's visible() closure type-hinted a
 * non-nullable ChatbotIndexEntry, so that call threw a TypeError on every
 * single visit to /portal/my-chatbots, for every tenant, regardless of how
 * many chatbots they had -- found from a real customer's report of a 500 on
 * their own account, which had two real chatbots. Never a zero-rows edge
 * case; the column-level call happens unconditionally on mount.
 */
class MyChatbotsPageTest extends TestCase
{
    use RefreshDatabase;

    private function makeOwnerWithChatbots(int $count): User
    {
        $plan = Plan::create([
            'name' => 'Test Plan', 'slug' => 'test-' . Str::random(8),
            'price_monthly' => 0, 'max_chatbots' => 5, 'max_tokens_monthly' => 1000000,
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

        for ($i = 0; $i < $count; $i++) {
            $chatbotId = (string) Str::uuid();
            DB::statement("SET search_path TO {$schema}, public");
            DB::table('chatbots')->insert([
                'id' => $chatbotId, 'name' => "Test Bot {$i}", 'type' => 'support', 'status' => 'active',
                'is_active' => true, 'welcome_message' => 'hi', 'language' => 'en',
                'widget_config' => json_encode([]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::statement('SET search_path TO public');

            DB::table('chatbot_index')->insert([
                'chatbot_id' => $chatbotId, 'tenant_id' => $tenant->id, 'schema_name' => $schema,
                'is_active' => true, 'name' => "Test Bot {$i}", 'primary_domain' => null,
            ]);
        }

        return User::create([
            'tenant_id' => $tenant->id,
            'email' => Str::random(10) . '@example.test',
            'password' => bcrypt('x'), 'password_hash' => bcrypt('x'),
            'name' => 'Owner', 'role' => 'owner', 'email_verified_at' => now(),
        ]);
    }

    public function test_the_page_loads_for_a_tenant_with_real_chatbots(): void
    {
        $owner = $this->makeOwnerWithChatbots(2);
        $this->actingAs($owner, 'web');

        Livewire::test(MyChatbots::class)->assertOk();
    }

    public function test_the_page_loads_for_a_tenant_with_no_chatbots_at_all(): void
    {
        $owner = $this->makeOwnerWithChatbots(0);
        $this->actingAs($owner, 'web');

        Livewire::test(MyChatbots::class)->assertOk();
    }
}
