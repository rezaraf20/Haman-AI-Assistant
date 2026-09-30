<?php
namespace Tests\Feature;

use App\Filament\Customer\Pages\{BuyChatbot, BuyTokens, Wallet};
use App\Models\{ChatbotTypePrice, Plan, Tenant, User};
use App\Services\TenantService;
use App\Support\{Money, Settings};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Money's currency helpers, Tenant::currency(), and the customer portal
 * pages that now read them — see PublicSiteTest for the landing page's own
 * half of this (locale-driven rather than country-driven).
 */
class TenantCurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Settings::forget();
        Money::forget();
    }

    private function portalUser(?string $country = null, int $walletBalanceToman = 0): User
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
            'country' => $country, 'wallet_balance_toman' => $walletBalanceToman,
        ]);
        $schema = 'tenant_' . str_replace('-', '', $tenant->id);
        $tenant->update(['schema_name' => $schema]);
        app(TenantService::class)->createSchema($schema);

        return User::create([
            'tenant_id' => $tenant->id,
            'email' => Str::random(10) . '@example.test',
            'password' => bcrypt('x'), 'password_hash' => bcrypt('x'),
            'name' => 'Portal User', 'role' => 'owner', 'locale' => 'en',
            'email_verified_at' => now(),
        ]);
    }

    // ── Money ──────────────────────────────────────────────────────────

    public function test_convert_is_identity_for_irt(): void
    {
        $this->assertSame(990000.0, Money::convert(990000, 'IRT'));
    }

    public function test_convert_returns_null_without_a_configured_rate(): void
    {
        $this->assertNull(Money::convert(990000, 'EUR'));
    }

    public function test_convert_divides_by_the_configured_rate(): void
    {
        Settings::set('payments.fx.eur_to_toman', 50000);
        $this->assertSame(20.0, Money::convert(990000, 'EUR'));
    }

    public function test_unit_label_reuses_the_settings_option_labels(): void
    {
        $this->assertSame(__('settings.option_IRT'), Money::unitLabel('IRT'));
        $this->assertSame(__('settings.option_EUR'), Money::unitLabel('EUR'));
    }

    public function test_unit_label_falls_back_to_the_raw_code_for_an_unlabelled_currency(): void
    {
        $this->assertSame('XYZ', Money::unitLabel('xyz'));
    }

    public function test_display_is_null_exactly_when_convert_is(): void
    {
        $this->assertNull(Money::display(990000, 'EUR'));

        Settings::set('payments.fx.eur_to_toman', 50000);
        $this->assertNotNull(Money::display(990000, 'EUR'));
    }

    public function test_currency_for_locale(): void
    {
        $this->assertSame('IRT', Money::currencyForLocale('fa'));
        $this->assertSame('EUR', Money::currencyForLocale('en'));
    }

    // ── Tenant::currency() ────────────────────────────────────────────

    public function test_a_tenant_with_no_country_is_toman(): void
    {
        $tenant = new Tenant(['country' => null]);
        $this->assertSame('IRT', $tenant->currency());
    }

    public function test_a_tenant_in_iran_is_toman(): void
    {
        $tenant = new Tenant(['country' => 'IR']);
        $this->assertSame('IRT', $tenant->currency());
    }

    public function test_a_tenant_outside_iran_is_euro(): void
    {
        $tenant = new Tenant(['country' => 'DE']);
        $this->assertSame('EUR', $tenant->currency());
    }

    // ── Money::forCurrentTenant() ─────────────────────────────────────

    public function test_for_current_tenant_reads_the_logged_in_users_own_tenant(): void
    {
        $irUser = $this->portalUser('IR');
        $this->actingAs($irUser, 'web');
        $this->assertStringContainsString(__('settings.option_IRT'), Money::forCurrentTenant(990000));

        $deUser = $this->portalUser('DE');
        Settings::set('payments.fx.eur_to_toman', 50000);
        $this->actingAs($deUser, 'web');
        Money::forget(); // the currency lookup above is memoized per process, not per actingAs() switch.
        $this->assertStringContainsString(__('settings.option_EUR'), Money::forCurrentTenant(990000));
    }

    public function test_for_current_tenant_falls_back_to_toman_without_a_configured_rate(): void
    {
        $deUser = $this->portalUser('DE');
        $this->actingAs($deUser, 'web');

        // payments.fx.eur_to_toman is unset — must still show a real
        // number, not blow up or show nothing.
        $this->assertStringContainsString(__('settings.option_IRT'), Money::forCurrentTenant(990000));
    }

    // ── Wallet page ───────────────────────────────────────────────────

    public function test_wallet_balance_shows_in_tomans_for_an_iran_tenant(): void
    {
        $user = $this->portalUser('IR', 5_000_000);
        $this->actingAs($user, 'web');

        Livewire::test(Wallet::class)
            ->assertOk()
            ->assertSee('5,000,000')
            ->assertSee(__('settings.option_IRT'));
    }

    public function test_wallet_balance_shows_in_euros_for_a_non_iran_tenant(): void
    {
        Settings::set('payments.fx.eur_to_toman', 50000);
        $user = $this->portalUser('DE', 5_000_000);
        $this->actingAs($user, 'web');

        Livewire::test(Wallet::class)
            ->assertOk()
            ->assertSee('100')
            ->assertSee(__('settings.option_EUR'));
    }

    /** Same balance, same value, as test_wallet_balance_shows_in_tomans_for_an_iran_tenant — this page used to hardcode "تومان" instead of going through Money, so it agreed with Wallet.php only for an Iran tenant, by coincidence. */
    public function test_buy_tokens_wallet_balance_shows_in_tomans_for_an_iran_tenant(): void
    {
        $user = $this->portalUser('IR', 5_000_000);
        $this->actingAs($user, 'web');

        Livewire::test(BuyTokens::class)
            ->assertOk()
            ->assertSee('5,000,000')
            ->assertSee(__('settings.option_IRT'));
    }

    /** The actual regression: a non-Iran tenant's balance must read in Euro here too, not the hardcoded "تومان" this page used to always print regardless of currency. */
    public function test_buy_tokens_wallet_balance_shows_in_euros_for_a_non_iran_tenant(): void
    {
        Settings::set('payments.fx.eur_to_toman', 50000);
        $user = $this->portalUser('DE', 5_000_000);
        $this->actingAs($user, 'web');

        Livewire::test(BuyTokens::class)
            ->assertOk()
            ->assertSee('100')
            ->assertSee(__('settings.option_EUR'))
            ->assertDontSee('تومان');
    }

    public function test_topup_is_available_for_an_iran_tenant(): void
    {
        $user = $this->portalUser('IR');
        $this->actingAs($user, 'web');

        $component = Livewire::test(Wallet::class);
        $this->assertTrue($component->instance()->canTopUp());
    }

    public function test_topup_is_unavailable_for_a_non_iran_tenant_with_no_gateway_configured(): void
    {
        $user = $this->portalUser('DE');
        $this->actingAs($user, 'web');

        $component = Livewire::test(Wallet::class);
        $this->assertFalse($component->instance()->canTopUp());
        $component->assertSee(__('wallet.topup_unavailable_currency'));
    }

    // ── BuyChatbot page ───────────────────────────────────────────────

    public function test_buy_chatbot_shows_the_type_price_in_the_tenants_currency(): void
    {
        ChatbotTypePrice::create(['type' => 'support', 'name' => 'Support', 'price_toman' => 500000, 'is_active' => true]);

        $user = $this->portalUser('IR');
        $this->actingAs($user, 'web');

        Livewire::test(BuyChatbot::class)->assertOk()->assertSee('500,000');
    }
}
