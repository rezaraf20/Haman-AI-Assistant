<?php
namespace Tests\Feature;

use App\Exceptions\InsufficientBalance;
use App\Filament\Customer\Pages\{BuyTokens, Invoices, MyChatbots};
use App\Models\{ChatbotIndexEntry, Invoice, Plan, Tenant, TokenPackage, User, WalletTransaction};
use App\Services\{BillingService, PurchaseService, TenantService, WalletService};
use App\Support\Settings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P0 of the billing audit: the wallet cannot be overspent, a paid plan is
 * actually billed every period (invoice issued ahead, charged from the
 * wallet, voided and downgraded when unpaid), and chatbots renew the same
 * way. Every assertion is on the ledger and the tenant row, not on a page.
 */
class BillingTest extends TestCase
{
    use RefreshDatabase;

    private Plan $free;
    private Plan $pro;
    private Plan $business;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-12-01 10:00:00', 'UTC'));
        Settings::forget();
        $this->free = Plan::firstOrCreate(['slug' => 'free'], ['name' => 'Free', 'price_monthly' => 0, 'max_chatbots' => 1, 'max_tokens_monthly' => 1000, 'is_active' => true, 'sort_order' => 0]);
        $this->pro = Plan::create(['name' => 'Pro', 'slug' => 'pro-' . Str::random(5), 'price_monthly' => 990000, 'max_chatbots' => 3,
            'max_tokens_monthly' => 1000000, 'is_active' => true, 'is_public' => true, 'sort_order' => 1, 'can_purchase_tokens' => true]);
        $this->business = Plan::create(['name' => 'Business', 'slug' => 'biz-' . Str::random(5), 'price_monthly' => 2900000, 'max_chatbots' => 10,
            'max_tokens_monthly' => 9000000, 'is_active' => true, 'is_public' => true, 'sort_order' => 2, 'can_purchase_tokens' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function tenant(Plan $plan, int $balance = 0, array $extra = []): Tenant
    {
        $tenant = Tenant::create(array_merge([
            'slug' => 't-' . Str::random(8), 'name' => 'Shop', 'email' => Str::random(10) . '@example.test',
            'plan_id' => $plan->id, 'schema_name' => 'placeholder', 'status' => 'active', 'language' => 'en',
            'wallet_balance_toman' => $balance, 'quota_period_started_at' => now()->subDays(10),
        ], $extra));
        $schema = 'tenant_' . str_replace('-', '', $tenant->id);
        $tenant->update(['schema_name' => $schema]);
        app(TenantService::class)->createSchema($schema);
        // A ledger row behind the starting balance, so wallet:reconcile agrees.
        if ($balance > 0) {
            WalletTransaction::create(['tenant_id' => $tenant->id, 'type' => 'topup', 'amount_toman' => $balance,
                'balance_after_toman' => $balance, 'status' => 'completed']);
        }
        return $tenant->fresh();
    }

    private function user(Tenant $tenant): User
    {
        return User::create(['tenant_id' => $tenant->id, 'email' => Str::random(10) . '@example.test', 'password' => bcrypt('x'),
            'password_hash' => bcrypt('x'), 'name' => 'Owner', 'role' => 'owner', 'email_verified_at' => now(), 'locale' => 'en']);
    }

    private function chatbot(Tenant $tenant, int $price, $expiresAt, bool $active = true, ?string $reason = null, ?string $domain = 'shop.example.test'): string
    {
        $id = (string) Str::uuid();
        DB::statement("SET search_path TO {$tenant->schema_name}, public");
        DB::table('chatbots')->insert(['id' => $id, 'name' => 'Bot', 'type' => 'support', 'status' => 'active',
            'is_active' => $active, 'language' => 'fa', 'created_at' => now(), 'updated_at' => now()]);
        DB::statement('SET search_path TO public');
        DB::table('chatbot_index')->insert(['chatbot_id' => $id, 'tenant_id' => $tenant->id, 'schema_name' => $tenant->schema_name,
            'is_active' => $active, 'disabled_reason' => $reason, 'name' => 'Bot', 'primary_domain' => $domain,
            'expires_at' => $expiresAt, 'monthly_price_toman' => $price]);
        return $id;
    }

    private function ledgerSum(Tenant $tenant): int
    {
        return (int) WalletTransaction::where('tenant_id', $tenant->id)->where('status', 'completed')->sum('amount_toman');
    }

    private function billing(): BillingService { return app(BillingService::class); }

    // ── The wallet cannot be overspent ─────────────────────────────────

    public function test_a_debit_checks_the_balance_in_the_database_not_the_callers_copy(): void
    {
        $tenant = $this->tenant($this->free, 100000);
        $stale = Tenant::find($tenant->id);                    // a page loaded this with 100,000
        app(WalletService::class)->debit($tenant, 80000, 'plan_charge');   // the first click

        try {
            app(WalletService::class)->debit($stale, 80000, 'plan_charge'); // the second click, same stale view
            $this->fail('the second debit must not go through');
        } catch (InsufficientBalance $e) {
            $this->assertSame(20000, $e->balance);
        }
        $this->assertSame(20000, (int) $tenant->fresh()->wallet_balance_toman);
        $this->assertSame(20000, $this->ledgerSum($tenant));
    }

    public function test_a_grant_that_fails_takes_the_charge_back_with_it(): void
    {
        $tenant = $this->tenant($this->free, 100000);
        try {
            app(WalletService::class)->debit($tenant, 50000, 'plan_charge', [], fn () => throw new \DomainException('boom'));
        } catch (\DomainException) {}

        $this->assertSame(100000, (int) $tenant->fresh()->wallet_balance_toman);
        $this->assertSame(1, WalletTransaction::where('tenant_id', $tenant->id)->count(), 'only the opening top-up');
    }

    public function test_tokens_are_granted_with_the_charge_or_not_at_all(): void
    {
        $tenant = $this->tenant($this->pro, 300000);
        $package = TokenPackage::create(['name' => 'Pack', 'token_amount' => 50000, 'price_toman' => 200000, 'is_active' => true]);

        app(PurchaseService::class)->buyTokens($tenant, $package);
        $this->assertSame(50000, (int) $tenant->fresh()->bonus_tokens);
        $this->assertSame(100000, (int) $tenant->fresh()->wallet_balance_toman);

        // Second purchase: not enough left — nothing moves.
        try { app(PurchaseService::class)->buyTokens($tenant, $package); } catch (InsufficientBalance) {}
        $this->assertSame(50000, (int) $tenant->fresh()->bonus_tokens);
        $this->assertSame(100000, (int) $tenant->fresh()->wallet_balance_toman);

        // A plan that cannot buy tokens: refused under the lock, charge rolled back.
        Tenant::where('id', $tenant->id)->update(['plan_id' => $this->free->id, 'wallet_balance_toman' => 300000]);
        $this->free->update(['can_purchase_tokens' => false]);
        try { app(PurchaseService::class)->buyTokens($tenant, $package); $this->fail(); } catch (\DomainException $e) {
            $this->assertSame('upgrade_required', $e->getMessage());
        }
        $this->assertSame(300000, (int) $tenant->fresh()->wallet_balance_toman);
    }

    public function test_the_buy_tokens_page_goes_through_the_service(): void
    {
        $tenant = $this->tenant($this->pro, 0);
        $package = TokenPackage::create(['name' => 'Pack', 'token_amount' => 50000, 'price_toman' => 200000, 'is_active' => true]);
        $this->actingAs($this->user($tenant), 'web');
        Filament::setCurrentPanel(Filament::getPanel('customer'));

        Livewire::test(BuyTokens::class)->callTableAction('buy', $package)->assertNotified(__('wallet.insufficient_balance'));
        $this->assertSame(0, (int) $tenant->fresh()->bonus_tokens);
    }

    // ── Plan upgrade ───────────────────────────────────────────────────

    public function test_an_upgrade_charges_the_prorated_difference_in_whole_toman_and_starts_billing(): void
    {
        // Quota period started 10 days ago → 2026-11-21 .. 2026-12-21: 20 of 30 days left.
        $tenant = $this->tenant($this->free, 5000000);
        $charged = $this->billing()->upgradePlan($tenant, $this->pro);

        $this->assertSame(intdiv(990000 * 20 * 86400, 30 * 86400), $charged);
        $this->assertSame(660000, $charged);
        $fresh = $tenant->fresh();
        $this->assertSame($this->pro->id, $fresh->plan_id);
        $this->assertSame('2026-12-21', $fresh->plan_paid_until->toDateString());
        $this->assertSame(5000000 - 660000, (int) $fresh->wallet_balance_toman);
        $this->assertSame((int) $fresh->wallet_balance_toman, $this->ledgerSum($tenant));
    }

    public function test_an_upgrade_without_the_balance_changes_nothing(): void
    {
        $tenant = $this->tenant($this->free, 1000);
        $this->expectException(InsufficientBalance::class);
        try {
            $this->billing()->upgradePlan($tenant, $this->pro);
        } finally {
            $this->assertSame($this->free->id, $tenant->fresh()->plan_id);
            $this->assertNull($tenant->fresh()->plan_paid_until);
        }
    }

    public function test_plan_prices_are_whole_toman(): void
    {
        $plan = Plan::create(['name' => 'X', 'slug' => 'x-' . Str::random(5), 'price_monthly' => 990000, 'price_yearly' => 990000 * 10.0,
            'max_chatbots' => 1, 'max_tokens_monthly' => 1, 'is_active' => true, 'sort_order' => 9]);
        $this->assertSame(9900000, $plan->fresh()->price_yearly);
        $this->assertSame(990000, $plan->fresh()->price_monthly);
        $plan->update(['price_monthly' => 1234.6]);
        $this->assertSame(1235, $plan->fresh()->price_monthly);
    }

    // ── Renewal: invoice, charge, grace, downgrade ─────────────────────

    public function test_the_renewal_invoice_is_issued_ahead_once_and_charged_on_the_due_date(): void
    {
        $tenant = $this->tenant($this->pro, 2000000, ['plan_paid_until' => now()->addDays(10)]);

        $this->billing()->run();
        $this->assertSame(0, Invoice::count(), 'not yet — 10 days out, lead is 7');

        Carbon::setTestNow(now()->addDays(4));                 // 6 days before the period ends
        $this->billing()->run();
        $this->billing()->run();                               // a second run issues nothing
        $invoice = Invoice::sole();
        $this->assertSame('open', $invoice->status);
        $this->assertSame(990000, $invoice->amount);
        $this->assertSame('2026-12-11', $invoice->period_start->toDateString());
        $this->assertSame('2027-01-11', $invoice->period_end->toDateString());
        $this->assertSame(2000000, (int) $tenant->fresh()->wallet_balance_toman, 'issuing charges nothing');

        Carbon::setTestNow(Carbon::parse('2026-12-11 11:00:00', 'UTC'));
        $this->billing()->run();
        $invoice->refresh();
        $this->assertSame('paid', $invoice->status);
        $this->assertNotNull($invoice->wallet_transaction_id);
        $this->assertSame(-990000, (int) WalletTransaction::find($invoice->wallet_transaction_id)->amount_toman);
        $fresh = $tenant->fresh();
        $this->assertSame('2027-01-11', $fresh->plan_paid_until->toDateString());
        $this->assertSame(1010000, (int) $fresh->wallet_balance_toman);
        $this->assertSame(1010000, $this->ledgerSum($tenant));

        $this->billing()->run();
        $this->assertSame(1010000, (int) $tenant->fresh()->wallet_balance_toman, 'paid once, not every hour');
    }

    public function test_an_unpaid_renewal_is_retried_then_voided_and_the_plan_drops_to_free(): void
    {
        $tenant = $this->tenant($this->pro, 0, ['plan_paid_until' => now()->addDay()]);
        $user = $this->user($tenant);

        $this->billing()->run();                               // issued
        Carbon::setTestNow(now()->addDay()->addHour());
        $this->billing()->run();                               // due: insufficient
        $this->billing()->run();                               // retried: still insufficient
        $invoice = Invoice::sole();
        $this->assertSame('open', $invoice->status);
        $this->assertSame(2, $invoice->attempts);
        $this->assertSame('insufficient_balance', $invoice->last_failure);
        $this->assertSame(1, $user->notifications()->where('data->title', __('billing.notice_unpaid_subject', ['number' => $invoice->number], 'en'))->count(),
            'told once, not on every retry');

        // Still inside the grace period the plan is kept.
        Carbon::setTestNow(now()->addDays(2));
        $this->billing()->run();
        $this->assertSame($this->pro->id, $tenant->fresh()->plan_id);

        Carbon::setTestNow(now()->addDays(2));                 // past 3 days of grace
        $this->billing()->run();
        $this->assertSame('void', $invoice->fresh()->status);
        $this->assertSame('unpaid', $invoice->fresh()->void_reason);
        $fresh = $tenant->fresh();
        $this->assertSame($this->free->id, $fresh->plan_id);
        $this->assertNull($fresh->plan_paid_until);
        $this->assertSame(0, (int) $fresh->wallet_balance_toman, 'nothing was taken');
    }

    public function test_a_top_up_during_grace_is_picked_up_by_the_next_run(): void
    {
        $tenant = $this->tenant($this->pro, 0, ['plan_paid_until' => now()->addDay()]);
        $this->billing()->run();
        Carbon::setTestNow(now()->addDay()->addHour());
        $this->billing()->run();
        $this->assertSame('open', Invoice::sole()->status);

        app(WalletService::class)->applyCompletedTransaction($tenant, 'topup', 1000000);
        $this->billing()->run();
        $this->assertSame('paid', Invoice::sole()->status);
        $this->assertSame(10000, (int) $tenant->fresh()->wallet_balance_toman);
        $this->assertSame($this->pro->id, $tenant->fresh()->plan_id);
    }

    public function test_a_tenant_on_a_paid_plan_with_no_paid_until_date_is_never_billed(): void
    {
        // Production has two tenants an admin put on Business by hand; billing
        // must not start on its own for them.
        $tenant = $this->tenant($this->business, 0, ['plan_paid_until' => null]);
        Carbon::setTestNow(now()->addMonths(3));
        $this->billing()->run();
        $this->assertSame(0, Invoice::count());
        $this->assertSame($this->business->id, $tenant->fresh()->plan_id);
    }

    public function test_a_scheduled_downgrade_is_billed_at_its_own_price_and_switches_on_payment(): void
    {
        $tenant = $this->tenant($this->business, 5000000, ['plan_paid_until' => now()->addDays(5)]);
        $effective = $this->billing()->scheduleDowngrade($tenant, $this->pro);
        $this->assertSame(now()->addDays(5)->toDateString(), $effective->toDateString());

        $this->billing()->run();
        $this->assertSame(990000, Invoice::sole()->amount);

        Carbon::setTestNow(now()->addDays(5)->addHour());
        $this->billing()->run();
        $fresh = $tenant->fresh();
        $this->assertSame($this->pro->id, $fresh->plan_id);
        $this->assertNull($fresh->pending_plan_id);
        $this->assertSame(5000000 - 990000, (int) $fresh->wallet_balance_toman);
    }

    public function test_a_downgrade_to_free_is_not_billed(): void
    {
        $tenant = $this->tenant($this->pro, 5000000, ['plan_paid_until' => now()->addDays(5)]);
        $this->free->update(['is_public' => true]);
        $this->billing()->scheduleDowngrade($tenant, $this->free);
        $this->billing()->run();
        $this->assertSame(0, Invoice::count());

        Carbon::setTestNow(now()->addDays(6));
        $this->artisan('plans:process-transitions')->assertSuccessful();
        $this->assertSame($this->free->id, $tenant->fresh()->plan_id);
        $this->assertNull($tenant->fresh()->plan_paid_until);
    }

    public function test_changing_plan_voids_the_invoice_issued_at_the_old_price(): void
    {
        $tenant = $this->tenant($this->pro, 9000000, ['plan_paid_until' => now()->addDays(5)]);
        $this->billing()->run();
        $old = Invoice::sole();

        $this->billing()->upgradePlan($tenant, $this->business);
        $this->assertSame('void', $old->fresh()->status);
        $this->assertSame('plan_changed', $old->fresh()->void_reason);

        $this->billing()->run();
        $new = Invoice::where('status', 'open')->sole();
        $this->assertSame(2900000, $new->amount);
    }

    // ── Chatbots renew the same way ────────────────────────────────────

    public function test_a_paid_chatbot_is_invoiced_charged_and_kept_running(): void
    {
        $tenant = $this->tenant($this->free, 1000000);
        $bot = $this->chatbot($tenant, 400000, now()->addDays(3));

        $this->billing()->run();
        $invoice = Invoice::sole();
        $this->assertSame(Invoice::CHATBOT, $invoice->purpose);

        Carbon::setTestNow(now()->addDays(3)->addHour());
        // The daily suspension job runs before the hourly charge would have.
        $this->artisan('chatbots:expire-overdue')->assertSuccessful();
        $this->assertTrue((bool) ChatbotIndexEntry::find($bot)->is_active, 'in grace: kept running');

        $this->billing()->run();
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(now()->subHour()->addMonthNoOverflow()->toDateString(), ChatbotIndexEntry::find($bot)->expires_at->toDateString());
        $this->assertSame(600000, (int) $tenant->fresh()->wallet_balance_toman);
    }

    public function test_paying_reactivates_a_chatbot_suspended_for_expiry_but_not_one_awaiting_its_domain(): void
    {
        $tenant = $this->tenant($this->free, 5000000);
        $expired = $this->chatbot($tenant, 400000, now()->subDay(), active: false, reason: 'expired');
        $noDomain = $this->chatbot($tenant, 400000, now()->subDay(), active: false, reason: 'awaiting_domain', domain: null);

        $this->billing()->run();
        $this->assertSame(2, Invoice::where('status', 'paid')->count());

        $this->assertTrue((bool) ChatbotIndexEntry::find($expired)->is_active);
        DB::statement("SET search_path TO {$tenant->schema_name}, public");
        $this->assertTrue((bool) DB::table('chatbots')->where('id', $expired)->value('is_active'), 'tenant-schema row in step');
        DB::statement('SET search_path TO public');
        $this->assertFalse((bool) ChatbotIndexEntry::find($noDomain)->is_active);
        $this->assertSame('awaiting_domain', ChatbotIndexEntry::find($noDomain)->disabled_reason);
    }

    public function test_renewing_by_hand_pays_the_open_invoice_instead_of_charging_twice(): void
    {
        $tenant = $this->tenant($this->free, 1000000);
        $bot = $this->chatbot($tenant, 400000, now()->addDays(3));
        $this->billing()->run();

        app(PurchaseService::class)->renewChatbot($tenant, ChatbotIndexEntry::find($bot));
        Carbon::setTestNow(now()->addDays(3)->addHour());
        $this->billing()->run();

        $this->assertSame('paid', Invoice::sole()->status);
        $this->assertSame(600000, (int) $tenant->fresh()->wallet_balance_toman, 'one month charged, once');
    }

    public function test_renewing_someone_elses_chatbot_is_refused(): void
    {
        $owner = $this->tenant($this->free, 0);
        $other = $this->tenant($this->free, 1000000);
        $bot = $this->chatbot($owner, 400000, now()->addDays(3));

        $this->expectException(\DomainException::class);
        app(PurchaseService::class)->renewChatbot($other, ChatbotIndexEntry::find($bot));
    }

    // ── Portal ─────────────────────────────────────────────────────────

    public function test_the_customer_pays_an_open_invoice_from_the_portal_and_sees_only_their_own(): void
    {
        $tenant = $this->tenant($this->pro, 0, ['plan_paid_until' => now()->addDays(2)]);
        $other = $this->tenant($this->pro, 0, ['plan_paid_until' => now()->addDays(2)]);
        $this->billing()->run();
        $mine = Invoice::where('tenant_id', $tenant->id)->sole();
        $theirs = Invoice::where('tenant_id', $other->id)->sole();

        $this->actingAs($this->user($tenant), 'web');
        Filament::setCurrentPanel(Filament::getPanel('customer'));

        Livewire::test(Invoices::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs])
            ->callTableAction('pay', $mine)
            ->assertNotified(__('wallet.insufficient_balance'));
        $this->assertSame('open', $mine->fresh()->status);

        app(WalletService::class)->applyCompletedTransaction($tenant, 'topup', 990000);
        Livewire::test(Invoices::class)->callTableAction('pay', $mine)->assertNotified(__('billing.paid_success'));
        $this->assertSame('paid', $mine->fresh()->status);
        $this->assertSame(0, (int) $tenant->fresh()->wallet_balance_toman);
        $this->assertSame(now()->addDays(2)->addMonthNoOverflow()->toDateString(), $tenant->fresh()->plan_paid_until->toDateString());
    }

    public function test_the_renew_button_charges_through_the_service(): void
    {
        $tenant = $this->tenant($this->free, 0);
        $bot = $this->chatbot($tenant, 400000, now()->addDays(20));
        $this->actingAs($this->user($tenant), 'web');
        Filament::setCurrentPanel(Filament::getPanel('customer'));

        Livewire::test(MyChatbots::class)->callTableAction('renew', ChatbotIndexEntry::find($bot))
            ->assertNotified(__('wallet.insufficient_balance'));
        $this->assertSame(now()->addDays(20)->toDateString(), ChatbotIndexEntry::find($bot)->expires_at->toDateString());
    }
}
