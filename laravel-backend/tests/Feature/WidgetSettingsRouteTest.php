<?php
namespace Tests\Feature;

use App\Filament\Customer\Pages\WidgetSettings;
use App\Models\{Plan, Tenant, User};
use App\Services\TenantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * WidgetSettings had no {chatbot} segment in its own route at all — Filament's
 * default slug is just the class's kebab-case name, and nothing here ever
 * overrode it — so mount(string $chatbot) could never actually receive one.
 * getUrl(['chatbot' => $id]), used both by MyChatbots's row action and (once
 * copied from there) the WordPress plugin's "edit in the portal" link, fell
 * back to appending it as a plain ?chatbot= query string instead, since
 * 'chatbot' matched no {} placeholder in the path — and every one of those
 * links 500'd (BindingResolutionException) rather than opening a usable page.
 * Found from a real user's report of exactly that error, on the plugin link.
 */
class WidgetSettingsRouteTest extends TestCase
{
    use RefreshDatabase;

    private function makeChatbotAndOwner(): array
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

        DB::statement("SET search_path TO {$schema}, public");
        DB::table('chatbots')->insert([
            'id' => $chatbotId, 'name' => 'Test Bot', 'type' => 'support', 'status' => 'active',
            'is_active' => true, 'welcome_message' => 'hi', 'language' => 'en',
            'widget_config' => json_encode([]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement('SET search_path TO public');

        DB::table('chatbot_index')->insert([
            'chatbot_id' => $chatbotId, 'tenant_id' => $tenant->id, 'schema_name' => $schema,
            'is_active' => true, 'name' => 'Test Bot', 'primary_domain' => null,
        ]);

        $owner = User::create([
            'tenant_id' => $tenant->id,
            'email' => Str::random(10) . '@example.test',
            'password' => bcrypt('x'), 'password_hash' => bcrypt('x'),
            'name' => 'Owner', 'role' => 'owner', 'email_verified_at' => now(),
        ]);

        return [$chatbotId, $owner];
    }

    public function test_the_generated_url_puts_the_chatbot_id_in_the_path_not_a_query_string(): void
    {
        $url = WidgetSettings::getUrl(['chatbot' => 'abc-123']);

        $this->assertStringContainsString('/widget-settings/abc-123', $url);
        $this->assertStringNotContainsString('?chatbot=', $url);
    }

    public function test_the_owner_of_the_chatbot_can_actually_open_its_widget_settings_page(): void
    {
        [$chatbotId, $owner] = $this->makeChatbotAndOwner();
        $this->actingAs($owner, 'web');

        $this->get(WidgetSettings::getUrl(['chatbot' => $chatbotId]))
            ->assertOk();
    }

    public function test_a_different_tenants_owner_cannot_open_someone_elses_chatbot_settings(): void
    {
        [$chatbotId] = $this->makeChatbotAndOwner();
        [, $otherOwner] = $this->makeChatbotAndOwner();
        $this->actingAs($otherOwner, 'web');

        $this->get(WidgetSettings::getUrl(['chatbot' => $chatbotId]))
            ->assertNotFound();
    }
}
