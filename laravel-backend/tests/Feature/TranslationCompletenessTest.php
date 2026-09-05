<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\{User, Tenant, Plan};
use App\Services\TenantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/**
 * Guards the bilingual (fa/en) i18n rollout two ways:
 *  1. lang/fa/*.php and lang/en/*.php must define the exact same key set —
 *     a key added to one and forgotten in the other is exactly how a raw
 *     "domain.key" string ends up rendered to a real user.
 *  2. Actually hitting admin and customer-portal pages under both locales
 *     and grepping the rendered HTML for that literal failure mode, in case
 *     a hardcoded string was missed during extraction rather than just
 *     under-translated. Covers both dashboards (App\Filament\Pages\Dashboard
 *     / App\Filament\Customer\Pages\Dashboard and their widgets).
 */
class TranslationCompletenessTest extends TestCase
{
    use RefreshDatabase;

    private const DOMAINS = ['common', 'panel', 'chatbot', 'wallet', 'ticket', 'plan', 'validation', 'dashboard'];

    public function test_fa_and_en_locale_files_have_matching_keys(): void
    {
        foreach (self::DOMAINS as $domain) {
            $fa = require base_path("lang/fa/{$domain}.php");
            $en = require base_path("lang/en/{$domain}.php");

            $faKeys = $this->sortedKeys($fa);
            $enKeys = $this->sortedKeys($en);

            $missingFromEn = array_diff($faKeys, $enKeys);
            $missingFromFa = array_diff($enKeys, $faKeys);

            $this->assertEmpty($missingFromEn, "lang/en/{$domain}.php is missing keys present in fa: " . implode(', ', $missingFromEn));
            $this->assertEmpty($missingFromFa, "lang/fa/{$domain}.php is missing keys present in en: " . implode(', ', $missingFromFa));
        }
    }

    public function test_admin_panel_renders_without_raw_translation_keys(): void
    {
        $admin = User::create([
            'email' => Str::random(10) . '@example.test',
            'password' => bcrypt('irrelevant'),
            'password_hash' => bcrypt('irrelevant'),
            'name' => 'Test Admin',
            'role' => 'owner',
            'email_verified_at' => now(),
        ]);

        $paths = ['/admin', '/admin/tenants', '/admin/chatbots', '/admin/api-keys', '/admin/tickets', '/admin/wallet-transactions'];

        foreach (['fa', 'en'] as $locale) {
            $admin->update(['locale' => $locale]);

            foreach ($paths as $path) {
                $response = $this->actingAs($admin, 'web')->get($path);
                $response->assertOk();
                $this->assertNoRawKeyLeaked($response->getContent(), $locale, $path);
            }
        }
    }

    public function test_customer_portal_dashboard_renders_without_raw_translation_keys(): void
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

        $customer = User::create([
            'tenant_id' => $tenant->id,
            'email' => Str::random(10) . '@example.test',
            'password' => bcrypt('irrelevant'),
            'password_hash' => bcrypt('irrelevant'),
            'name' => 'Test Customer',
            'role' => 'owner',
            'email_verified_at' => now(),
        ]);

        // No chatbot/sync/conversation data at all — the dashboard's root
        // page should render the onboarding checklist (see
        // OnboardingChecklist widget), not empty charts, and definitely no
        // raw translation keys either way.
        foreach (['fa', 'en'] as $locale) {
            $customer->update(['locale' => $locale]);

            $response = $this->actingAs($customer, 'web')->get('/portal');
            $response->assertOk();
            $this->assertNoRawKeyLeaked($response->getContent(), $locale, '/portal');
        }
    }

    private function sortedKeys(array $arr): array {
        $keys = array_keys($arr);
        sort($keys);
        return $keys;
    }

    private function assertNoRawKeyLeaked(string $html, string $locale, string $path): void {
        foreach (self::DOMAINS as $domain) {
            preg_match_all('/\b' . $domain . '\.[a-z_0-9]+\b/', $html, $matches);
            $this->assertEmpty($matches[0], "Raw translation key(s) leaked into {$path} ({$locale}): " . implode(', ', $matches[0]));
        }
    }
}
