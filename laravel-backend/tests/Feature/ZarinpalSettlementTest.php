<?php
namespace Tests\Feature;

use App\Filament\Resources\InvoiceResource\Pages\ListInvoices;
use App\Filament\Resources\WalletTransactionResource\Pages\CreateWalletTransaction;
use App\Models\{Invoice, Plan, Tenant, User, WalletTransaction};
use App\Services\{PaymentService, WalletService};
use App\Support\Settings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\{DB, Http};
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Zarinpal top-ups are settled by Zarinpal's own verify answer only — not
 * by the public callback's Status parameter, and not abandoned when the
 * gateway does not answer. Plus the admin money actions' audit trail.
 */
class ZarinpalSettlementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Settings::forget();
        Settings::set('payments.zarinpal.merchant_id', 'merchant-abc');
    }

    private function tenant(): Tenant
    {
        $plan = Plan::create(['name' => 'P', 'slug' => 'p-' . Str::random(6), 'price_monthly' => 0, 'max_chatbots' => 1,
            'max_tokens_monthly' => 1000, 'is_active' => true, 'sort_order' => 0]);
        return Tenant::create(['slug' => 't-' . Str::random(6), 'name' => 'Customer', 'email' => Str::random(8) . '@example.test',
            'plan_id' => $plan->id, 'schema_name' => 'public', 'status' => 'active', 'wallet_balance_toman' => 0]);
    }

    private function startTopup(Tenant $tenant, string $authority = 'AUTH-1', bool $fake = true): WalletTransaction
    {
        if ($fake) Http::fake(['*/request.json' => Http::response(['data' => ['code' => 100, 'authority' => $authority]])]);
        $this->assertTrue(app(PaymentService::class)->initTopup($tenant, 25000, 'https://example.test/cb')['ok']);
        return WalletTransaction::where('gateway_authority', $authority)->sole();
    }

    public function test_a_forged_nok_cannot_fail_a_payment_zarinpal_says_was_made(): void
    {
        $tenant = $this->tenant();
        $txn = $this->startTopup($tenant);
        Http::fake(['*/verify.json' => Http::response(['data' => ['code' => 100, 'ref_id' => 555]])]);

        $result = app(PaymentService::class)->handleCallback('AUTH-1', 'NOK');

        $this->assertTrue($result['ok']);
        $this->assertSame('completed', $txn->fresh()->status);
        $this->assertSame(25000, (int) $tenant->fresh()->wallet_balance_toman);
    }

    public function test_a_real_cancel_is_failed_on_zarinpals_word(): void
    {
        $tenant = $this->tenant();
        $txn = $this->startTopup($tenant);
        Http::fake(['*/verify.json' => Http::response(['data' => [], 'errors' => ['code' => -51, 'message' => 'Session is not valid']], 422)]);

        $result = app(PaymentService::class)->handleCallback('AUTH-1', 'NOK');

        $this->assertFalse($result['ok']);
        $this->assertSame('failed', $txn->fresh()->status);
        $this->assertSame(0, (int) $tenant->fresh()->wallet_balance_toman);
    }

    public function test_no_answer_from_zarinpal_leaves_the_payment_pending_not_failed(): void
    {
        $tenant = $this->tenant();
        $txn = $this->startTopup($tenant);
        Http::fake(['*/verify.json' => fn () => throw new ConnectionException('timed out')]);

        $result = app(PaymentService::class)->handleCallback('AUTH-1', 'OK');

        $this->assertFalse($result['ok']);
        $this->assertTrue($result['pending']);
        $this->assertSame('pending', $txn->fresh()->status);

        $this->get('/payments/zarinpal/callback?Authority=AUTH-1&Status=OK')->assertRedirectContains('topup=pending');
    }

    public function test_the_reverify_job_credits_a_payment_whose_callback_never_came(): void
    {
        $tenant = $this->tenant();
        $txn = $this->startTopup($tenant);
        $txn->forceFill(['created_at' => now()->subMinutes(30)])->save();
        Http::fake(['*/verify.json' => Http::response(['data' => ['code' => 100, 'ref_id' => 777]])]);

        $this->artisan('payments:reverify-pending')->assertSuccessful();
        $this->artisan('payments:reverify-pending')->assertSuccessful();   // idempotent

        $this->assertSame('completed', $txn->fresh()->status);
        $this->assertSame('777', $txn->fresh()->gateway_ref_id);
        $this->assertSame(25000, (int) $tenant->fresh()->wallet_balance_toman);
    }

    public function test_the_reverify_job_leaves_fresh_ones_and_gives_up_on_silent_ones(): void
    {
        $tenant = $this->tenant();
        Http::fake(['*/request.json' => Http::sequence()
            ->push(['data' => ['code' => 100, 'authority' => 'AUTH-NEW']])
            ->push(['data' => ['code' => 100, 'authority' => 'AUTH-OLD']])]);
        $fresh = $this->startTopup($tenant, 'AUTH-NEW', fake: false);
        $old = $this->startTopup($tenant, 'AUTH-OLD', fake: false);
        $old->forceFill(['created_at' => now()->subHours(80)])->save();
        Http::fake(['*/verify.json' => fn () => throw new ConnectionException('timed out')]);

        $this->artisan('payments:reverify-pending')->assertSuccessful();

        $this->assertSame('pending', $fresh->fresh()->status, 'younger than 5 minutes: its own callback may still come');
        $this->assertSame('failed', $old->fresh()->status);
        $this->assertSame(0, (int) $tenant->fresh()->wallet_balance_toman);
    }

    // ── Admin money actions are in the activity log ────────────────────

    private function admin(): User
    {
        $user = User::create(['email' => Str::random(10) . '@example.test', 'password' => bcrypt('x'), 'password_hash' => bcrypt('x'),
            'name' => 'Admin', 'role' => 'owner', 'email_verified_at' => now(), 'locale' => 'en']);
        $user->platform_role = 'admin';
        $user->platform_is_active = true;
        $user->save();
        return $user;
    }

    public function test_a_manual_wallet_adjustment_is_recorded_with_its_reason(): void
    {
        $tenant = $this->tenant();
        $this->actingAs($this->admin(), 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(CreateWalletTransaction::class)
            ->fillForm(['tenant_id' => $tenant->id, 'amount_toman' => 150000, 'description' => 'card-to-card deposit #42'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(150000, (int) $tenant->fresh()->wallet_balance_toman);
        $row = DB::table('platform_activity_log')->where('action', 'wallet_adjusted')->sole();
        $this->assertSame((string) $tenant->id, (string) $row->tenant_id);
        $this->assertSame('card-to-card deposit #42', json_decode($row->after, true)['note']);
    }

    public function test_voiding_an_invoice_needs_a_reason_and_is_recorded(): void
    {
        $tenant = $this->tenant();
        $invoice = Invoice::create(['tenant_id' => $tenant->id, 'purpose' => Invoice::PLAN, 'subject_id' => (string) $tenant->id,
            'description' => 'Pro', 'amount' => 990000, 'period_start' => now(), 'period_end' => now()->addMonth(),
            'status' => 'open', 'issued_at' => now(), 'due_at' => now()]);
        $this->actingAs($this->admin(), 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListInvoices::class)->callTableAction('void', $invoice, ['reason' => ''])->assertHasTableActionErrors(['reason']);
        $this->assertSame('open', $invoice->fresh()->status);

        Livewire::test(ListInvoices::class)->callTableAction('void', $invoice, ['reason' => 'customer moved to annual billing']);
        $this->assertSame('void', $invoice->fresh()->status);
        $this->assertSame('admin', $invoice->fresh()->void_reason);
        $this->assertSame('customer moved to annual billing',
            json_decode(DB::table('platform_activity_log')->where('action', 'invoice_voided')->value('after'), true)['note']);
    }
}
