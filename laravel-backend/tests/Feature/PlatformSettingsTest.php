<?php
namespace Tests\Feature;

use App\Filament\Pages\Settings as SettingsPage;
use App\Models\{Plan, Tenant, User};
use App\Services\Payments\{PaddleGateway, PaymentGatewayManager, StripeGateway, ZarinpalGateway};
use App\Services\PaymentService;
use App\Services\WalletService;
use App\Support\{LlmKeyCrypto, MailSettings, Settings, SettingsRegistry};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http};
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The settings page and everything that now reads from it.
 *
 * The test that matters most is the one proving a changed setting actually
 * reaches the code — a settings UI whose values nothing consults is worse
 * than a hardcoded number, because it looks like it works.
 */
class PlatformSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Settings::forget();
    }

    private function admin(): User
    {
        $user = User::create([
            'email' => Str::random(10) . '@example.test',
            'password' => bcrypt('x'), 'password_hash' => bcrypt('x'),
            'name' => 'Admin', 'role' => 'owner', 'email_verified_at' => now(),
        ]);
        $user->platform_role = 'admin';
        $user->platform_is_active = true;
        $user->save();

        return $user;
    }

    // ── Storage ─────────────────────────────────────────────────────────

    public function test_an_unset_setting_returns_its_declared_default(): void
    {
        $this->assertSame(20, Settings::get('limits.chat_session_per_minute'));
        $this->assertSame(100, Settings::get('limits.pdf_max_pages'));
        $this->assertSame(0.60, Settings::get('limits.retrieval_threshold'));
        $this->assertTrue(Settings::get('payments.zarinpal.sandbox'));
    }

    public function test_an_unknown_key_throws_rather_than_returning_null(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // A silent null here is how a rate limit becomes unlimited.
        Settings::get('limits.a_key_nobody_declared');
    }

    public function test_only_values_that_differ_from_the_default_are_stored(): void
    {
        Settings::set('limits.pdf_max_pages', 100);   // same as the default
        $this->assertArrayNotHasKey(
            'limits.pdf_max_pages',
            DB::table('platform_settings')->value('values') ? json_decode(DB::table('platform_settings')->value('values'), true) : [],
            'Storing the default would freeze it against a future change in code.'
        );

        Settings::set('limits.pdf_max_pages', 25);
        $this->assertSame(25, Settings::get('limits.pdf_max_pages'));
        $this->assertTrue(Settings::isOverridden('limits.pdf_max_pages'));
    }

    public function test_reset_puts_the_declared_default_back(): void
    {
        Settings::set('limits.chat_message_per_minute', 999);
        $this->assertSame(999, Settings::get('limits.chat_message_per_minute'));

        Settings::reset('limits.chat_message_per_minute');

        $this->assertSame(30, Settings::get('limits.chat_message_per_minute'));
        $this->assertFalse(Settings::isOverridden('limits.chat_message_per_minute'));
    }

    public function test_legacy_column_backed_settings_read_and_write_the_column(): void
    {
        Settings::set('sms.cost_toman', 450);

        $this->assertSame(450, Settings::get('sms.cost_toman'));
        $this->assertEquals(450, DB::table('platform_settings')->value('sms_cost_toman'),
            'Other services and Python read this column directly, so it must stay a column.');
    }

    // ── Secrets ─────────────────────────────────────────────────────────

    public function test_a_secret_is_encrypted_at_rest(): void
    {
        Settings::set('payments.stripe.secret_key', 'sk_live_supersecret');

        $stored = json_decode(DB::table('platform_settings')->value('values'), true);
        $raw = $stored['payments.stripe.secret_key'];

        $this->assertStringNotContainsString('sk_live_supersecret', $raw);
        $this->assertSame('sk_live_supersecret', LlmKeyCrypto::decrypt($raw));
        $this->assertSame('sk_live_supersecret', Settings::get('payments.stripe.secret_key'));
    }

    public function test_a_secret_is_never_handed_to_the_view(): void
    {
        Settings::set('payments.stripe.secret_key', 'sk_live_supersecret');

        $this->assertNull(Settings::redactedFor('payments.stripe.secret_key'));
        $this->assertTrue(Settings::isSet('payments.stripe.secret_key'),
            'The page still has to know it is configured, just not what it is.');
    }

    public function test_every_secret_key_is_redacted(): void
    {
        foreach (array_keys(SettingsRegistry::all()) as $key) {
            if (!SettingsRegistry::isSecret($key)) continue;

            Settings::set($key, 'value-for-' . $key);
            $this->assertNull(Settings::redactedFor($key), "{$key} leaked to the view layer.");
        }
    }

    public function test_saving_the_page_with_an_empty_secret_field_keeps_the_stored_value(): void
    {
        $this->actingAs($this->admin(), 'web');
        Settings::set('payments.stripe.secret_key', 'sk_live_keepme');

        $page = new SettingsPage();
        $page->mount();
        // The form never received the secret, so it submits empty.
        $page->data['payments__stripe__secret_key'] = null;
        $page->data['mail__host'] = 'smtp.example.test';
        $page->save();

        $this->assertSame('sk_live_keepme', Settings::get('payments.stripe.secret_key'),
            'Saving the page must not wipe every key on it.');
        $this->assertSame('smtp.example.test', Settings::get('mail.host'));
    }

    // ── Configured / not configured ─────────────────────────────────────

    public function test_a_group_with_nothing_set_reports_as_not_configured(): void
    {
        $this->assertFalse(Settings::isConfigured('stripe'));
        $this->assertFalse(Settings::isConfigured('paddle'));
        $this->assertFalse(Settings::isConfigured('email'));
    }

    public function test_a_group_reports_configured_only_when_every_required_key_is_set(): void
    {
        Settings::set('mail.host', 'smtp.example.test');
        $this->assertFalse(Settings::isConfigured('email'), 'from_address is still missing.');

        Settings::set('mail.from_address', 'bot@example.test');
        $this->assertTrue(Settings::isConfigured('email'));
    }

    public function test_zarinpal_reports_configured_once_it_has_a_merchant_id(): void
    {
        $this->assertFalse(Settings::isConfigured('zarinpal'));

        Settings::set('payments.zarinpal.merchant_id', 'merchant-abc');

        $this->assertTrue(Settings::isConfigured('zarinpal'));
    }

    // ── The code actually reads the settings ────────────────────────────

    public function test_the_pdf_page_limit_the_python_service_reads_comes_from_settings(): void
    {
        Settings::set('limits.pdf_max_pages', 7);

        $values = json_decode(DB::table('platform_settings')->value('values'), true);

        // This is the exact row platform_settings_service.py reads.
        $this->assertSame(7, $values['limits.pdf_max_pages']);
    }

    public function test_the_chat_rate_limiter_uses_the_configured_number(): void
    {
        Settings::set('limits.chat_message_per_minute', 2);

        $limiter = app(\Illuminate\Cache\RateLimiting\Limit::class, []);
        $resolved = \Illuminate\Support\Facades\RateLimiter::limiter('chat-message');
        $limit = $resolved(\Illuminate\Http\Request::create('/api/v1/chat/message', 'POST'));

        $this->assertSame(2, $limit->maxAttempts,
            'The limiter must read the setting per request, not capture it at boot.');
    }

    public function test_the_otp_ttl_used_when_issuing_a_code_comes_from_settings(): void
    {
        Settings::set('limits.otp_ttl_minutes', 11);

        $this->assertSame(11, Settings::get('limits.otp_ttl_minutes'));

        // SmsService stamps expires_at with exactly this value.
        $source = file_get_contents(app_path('Services/SmsService.php'));
        $this->assertStringContainsString("Settings::get('limits.otp_ttl_minutes')", $source);
        $this->assertStringNotContainsString('CODE_TTL_MINUTES', $source);
    }

    public function test_no_limit_the_settings_page_owns_is_still_hardcoded_in_the_controller(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Api/V1/ChatController.php'));

        foreach ([
            'MAX_PAYMENT_LINKS_PER_CONVERSATION',
            'MAX_PAYMENT_LINKS_PER_IP_PER_DAY',
            'MAX_CODES_PER_CONTACT_PER_HOUR',
            'MAX_CODES_PER_CHATBOT_PER_DAY',
            'MAX_CODE_REQUESTS_PER_IP_PER_DAY',
            'MAX_ORDER_STATUS_VERIFY_ATTEMPTS',
            'ORDER_STATUS_CODE_TTL_MINUTES',
        ] as $constant) {
            $this->assertStringNotContainsString($constant, $source,
                "{$constant} is still a constant; the settings value would be ignored.");
        }
    }

    // ── Payment gateways ────────────────────────────────────────────────

    public function test_zarinpal_still_works_through_the_gateway_layer(): void
    {
        Settings::set('payments.zarinpal.merchant_id', 'merchant-abc');
        Settings::set('payments.zarinpal.sandbox', true);

        Http::fake([
            '*/request.json' => Http::response(['data' => ['code' => 100, 'authority' => 'A00000000000000000000000000000012345']]),
        ]);

        $gateway = app(ZarinpalGateway::class);

        $this->assertSame('zarinpal', $gateway->key());
        $this->assertTrue($gateway->isConfigured());
        $this->assertTrue($gateway->supportsCurrency('IRT'));

        $result = $gateway->requestPayment(50000, 'IRT', 'https://example.test/callback', 'Top-up');

        $this->assertTrue($result['ok']);
        $this->assertSame('A00000000000000000000000000000012345', $result['reference']);
        $this->assertStringContainsString('sandbox.zarinpal.com', $result['redirect_url']);

        // The Toman-to-Rial ×10 is the classic bug for this gateway.
        Http::assertSent(fn ($request) => $request['amount'] === 500000);
    }

    public function test_zarinpal_verify_treats_an_already_verified_payment_as_success(): void
    {
        Settings::set('payments.zarinpal.merchant_id', 'merchant-abc');

        Http::fake(['*/verify.json' => Http::response(['data' => ['code' => 101, 'ref_id' => 777]])]);

        $result = app(ZarinpalGateway::class)->verifyPayment(50000, 'IRT', 'AUTH');

        $this->assertTrue($result['ok'], 'Code 101 is Zarinpal-side idempotency, not a failure.');
        $this->assertSame('777', $result['ref_id']);
    }

    public function test_a_topup_still_runs_end_to_end_through_zarinpal(): void
    {
        Settings::set('payments.zarinpal.merchant_id', 'merchant-abc');

        Http::fake([
            '*/request.json' => Http::response(['data' => ['code' => 100, 'authority' => 'AUTH-123']]),
            '*/verify.json'  => Http::response(['data' => ['code' => 100, 'ref_id' => 999]]),
        ]);

        $tenant = $this->tenant();
        $service = app(PaymentService::class);

        $init = $service->initTopup($tenant, 25000, 'https://example.test/cb');
        $this->assertTrue($init['ok'], $init['message'] ?? '');

        $callback = $service->handleCallback('AUTH-123', 'OK');

        $this->assertTrue($callback['ok'], $callback['message']);
        $this->assertEquals(25000, $tenant->fresh()->wallet_balance_toman);
    }

    public function test_an_unconfigured_gateway_refuses_rather_than_calling_the_api(): void
    {
        Http::fake();

        $result = app(StripeGateway::class)->requestPayment(100, 'USD', 'https://example.test/cb', 'x');

        $this->assertFalse($result['ok']);
        Http::assertNothingSent();
    }

    public function test_each_gateway_declares_the_currencies_it_can_take(): void
    {
        $this->assertTrue(app(ZarinpalGateway::class)->supportsCurrency('IRT'));
        $this->assertFalse(app(ZarinpalGateway::class)->supportsCurrency('USD'));

        $this->assertTrue(app(StripeGateway::class)->supportsCurrency('USD'));
        $this->assertFalse(app(StripeGateway::class)->supportsCurrency('IRT'));

        $this->assertTrue(app(PaddleGateway::class)->supportsCurrency('EUR'));
    }

    public function test_the_manager_routes_a_currency_to_its_configured_gateway(): void
    {
        $manager = app(PaymentGatewayManager::class);

        $this->assertSame('zarinpal', $manager->forCurrency('IRT')?->key());
        $this->assertSame('stripe', $manager->forCurrency('USD')?->key());

        Settings::set('payments.gateway.USD', 'paddle');
        $this->assertSame('paddle', $manager->forCurrency('USD')?->key());
    }

    public function test_routing_a_currency_to_a_gateway_that_cannot_take_it_yields_nothing(): void
    {
        Settings::set('payments.gateway.USD', 'zarinpal');

        $this->assertNull(
            app(PaymentGatewayManager::class)->forCurrency('USD'),
            'Silently using a different processor than the one chosen is worse than failing.'
        );
    }

    public function test_a_foreign_currency_topup_without_a_rate_is_refused(): void
    {
        $manager = app(PaymentGatewayManager::class);

        $this->assertNull($manager->toToman(100, 'USD'), 'A guessed rate is a wrong balance.');

        Settings::set('payments.fx.usd_to_toman', 60000);
        $this->assertSame(6000000, $manager->toToman(100, 'USD'));
    }

    // ── Email ───────────────────────────────────────────────────────────

    public function test_email_is_dropped_from_the_notification_chain_when_unconfigured(): void
    {
        $this->assertFalse(MailSettings::isUsable());
        $this->assertFalse(\App\Services\NotificationService::channelAvailable('email'));

        // The other channels are a chatbot's own business and stay available.
        $this->assertTrue(\App\Services\NotificationService::channelAvailable('telegram'));
        $this->assertTrue(\App\Services\NotificationService::channelAvailable('webhook'));
    }

    public function test_email_becomes_available_once_configured(): void
    {
        Settings::set('mail.host', 'smtp.example.test');
        Settings::set('mail.from_address', 'bot@example.test');

        $this->assertTrue(MailSettings::isUsable());
        $this->assertTrue(\App\Services\NotificationService::channelAvailable('email'));
    }

    public function test_a_test_send_is_refused_when_smtp_is_not_configured(): void
    {
        $result = MailSettings::sendTest('someone@example.test');

        $this->assertFalse($result['ok']);
    }

    public function test_the_smtp_settings_reach_the_live_mail_config(): void
    {
        Settings::set('mail.host', 'smtp.example.test');
        Settings::set('mail.port', 2525);
        Settings::set('mail.encryption', 'ssl');
        Settings::set('mail.from_address', 'bot@example.test');
        Settings::set('mail.from_name', 'Probe');

        MailSettings::apply();

        $this->assertSame('smtp.example.test', config('mail.mailers.smtp.host'));
        $this->assertSame(2525, config('mail.mailers.smtp.port'));
        $this->assertSame('ssl', config('mail.mailers.smtp.encryption'));
        $this->assertSame('bot@example.test', config('mail.from.address'));
        $this->assertSame('Probe', config('mail.from.name'));
    }

    public function test_encryption_set_to_none_becomes_null_not_the_string_none(): void
    {
        Settings::set('mail.host', 'smtp.example.test');
        Settings::set('mail.from_address', 'bot@example.test');
        Settings::set('mail.encryption', 'none');

        MailSettings::apply();

        $this->assertNull(config('mail.mailers.smtp.encryption'));
    }

    // ── Maintenance mode ────────────────────────────────────────────────

    public function test_maintenance_mode_takes_the_chat_endpoints_out_of_service(): void
    {
        Settings::set('system.maintenance_mode', true);
        Settings::set('system.maintenance_message', 'Back in ten minutes.');

        $response = $this->postJson('/api/v1/chat/session', ['chatbot_id' => (string) Str::uuid()]);

        $response->assertStatus(503);
        $this->assertSame('Back in ten minutes.', $response->json('message'));
        $this->assertSame('300', $response->headers->get('Retry-After'));
    }

    public function test_maintenance_mode_leaves_the_admin_panel_reachable(): void
    {
        Settings::set('system.maintenance_mode', true);

        // The owner has to be able to get in to turn it back off.
        $this->actingAs($this->admin(), 'web')->get('/admin')->assertOk();
    }

    // ── The page ────────────────────────────────────────────────────────

    public function test_support_cannot_reach_the_settings_page(): void
    {
        $support = User::create([
            'email' => 'sup@example.test', 'password' => bcrypt('x'), 'password_hash' => bcrypt('x'),
            'name' => 'S', 'role' => 'owner', 'email_verified_at' => now(),
        ]);
        $support->platform_role = 'support';
        $support->platform_is_active = true;
        $support->save();

        $this->actingAs($support, 'web')->get('/admin/settings')->assertForbidden();
    }

    public function test_an_admin_can_reach_the_settings_page(): void
    {
        $this->actingAs($this->admin(), 'web')->get('/admin/settings')->assertOk();
    }

    public function test_every_declared_setting_has_a_label_in_both_languages(): void
    {
        $fa = require lang_path('fa/settings.php');
        $en = require lang_path('en/settings.php');

        foreach (array_keys(SettingsRegistry::all()) as $key) {
            $name = 'field_' . str_replace('.', '_', $key);
            $this->assertArrayHasKey($name, $fa, "{$key} has no Persian label.");
            $this->assertArrayHasKey($name, $en, "{$key} has no English label.");
        }
    }

    public function test_every_select_option_has_a_label(): void
    {
        $fa = require lang_path('fa/settings.php');

        foreach (SettingsRegistry::all() as $key => $definition) {
            if ($definition['type'] !== 'select') continue;

            foreach ($definition['options'] as $option) {
                $this->assertArrayHasKey('option_' . $option, $fa, "Option {$option} ({$key}) has no label.");
            }
        }
    }

    public function test_every_tab_renders_for_an_admin(): void
    {
        $this->actingAs($this->admin(), 'web');

        // Nothing is configured in a fresh install, so rendering here also
        // exercises the not-configured state of every badge on every tab.
        \Livewire\Livewire::test(SettingsPage::class)->assertOk();

        foreach (SettingsRegistry::TABS as $tab) {
            $this->assertNotEmpty(SettingsRegistry::keysForTab($tab), "Tab {$tab} has no settings.");
        }
    }

    public function test_saving_a_change_is_recorded_in_the_activity_log(): void
    {
        $this->actingAs($this->admin(), 'web');

        $page = new SettingsPage();
        $page->mount();
        $page->data['limits__pdf_max_pages'] = 42;
        $page->save();

        $row = DB::table('platform_activity_log')->where('action', 'platform_settings_changed')->first();

        $this->assertNotNull($row, 'A settings change is a platform action like any other.');
        $this->assertSame(42, json_decode($row->after, true)['limits.pdf_max_pages']);
    }

    public function test_a_secret_change_is_recorded_without_the_value(): void
    {
        $this->actingAs($this->admin(), 'web');

        $page = new SettingsPage();
        $page->mount();
        $page->data['payments__stripe__secret_key'] = 'sk_live_donotlog';
        $page->save();

        $row = DB::table('platform_activity_log')->where('action', 'platform_settings_changed')->first();

        $this->assertNotNull($row);
        $this->assertStringNotContainsString('sk_live_donotlog', (string) $row->after);
        $this->assertStringNotContainsString('sk_live_donotlog', (string) $row->before);
    }

    public function test_the_health_checks_report_each_dependency_separately(): void
    {
        $this->actingAs($this->admin(), 'web');

        $checks = (new SettingsPage())->healthChecks();

        foreach (['database', 'redis', 'queue', 'python'] as $name) {
            $this->assertArrayHasKey($name, $checks);
            $this->assertArrayHasKey('ok', $checks[$name]);
        }

        $this->assertTrue($checks['database']['ok'], 'The test suite is talking to a database right now.');
    }

    private function tenant(): Tenant
    {
        $plan = Plan::create([
            'name' => 'P', 'slug' => 'p-' . Str::random(6), 'price_monthly' => 0,
            'max_chatbots' => 1, 'max_tokens_monthly' => 1000, 'is_active' => true, 'sort_order' => 0,
        ]);

        return Tenant::create([
            'slug' => 't-' . Str::random(6), 'name' => 'Customer',
            'email' => Str::random(8) . '@example.test', 'plan_id' => $plan->id,
            'schema_name' => 'public', 'status' => 'active', 'trial_ends_at' => now()->addDay(),
            'wallet_balance_toman' => 0,
        ]);
    }
}
