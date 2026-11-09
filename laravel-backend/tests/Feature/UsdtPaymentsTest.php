<?php
namespace Tests\Feature;

use App\Exceptions\InsufficientBalance;
use App\Models\Crypto\{CryptoDeposit, CryptoTransfer};
use App\Models\{Invoice, Plan, Tenant, TokenPackage, UsdtWalletTransaction, WalletTransaction};
use App\Services\{BillingService, PurchaseService, TenantService, WalletService};
use App\Services\Crypto\{BscRpc, RpcUnavailable, UsdtDeposits, UsdtScanner};
use App\Support\{Settings, Usdt};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * USDT (BEP20) into the tenant's own USDT wallet, against a fake chain.
 *
 * The fake answers the same JSON-RPC calls a real BSC node does (checked
 * against bsc-rpc.publicnode.com on 2026-11-10: chainId 0x38, USDT
 * decimals 18, the "finalized" tag, eth_getLogs filtered by recipient).
 */
class UsdtPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private const OURS = '0x1111111111111111111111111111111111111111';
    private const PAYER = '0x2222222222222222222222222222222222222222';

    /** The fake chain. */
    private int $head = 1000;
    private int $finalized = 997;
    private int $chainId = 56;
    /** @var array<int, array> */
    private array $logs = [];
    /** @var array<string, array|null> tx hash => receipt override */
    private array $receipts = [];
    private bool $primaryDown = false;
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-12-01 10:00:00', 'UTC'));
        Settings::forget();
        Settings::set('crypto.usdt_enabled', true);
        Settings::set('crypto.usdt_address', self::OURS);
        Settings::set('crypto.bsc_rpc_primary', 'https://primary.rpc.test');
        Settings::set('crypto.bsc_rpc_fallback', 'https://fallback.rpc.test');

        Http::fake(function ($request) {
            $host = parse_url($request->url(), PHP_URL_HOST);
            $method = $request['method'];
            $this->calls[] = "{$host} {$method}";
            if ($host === 'primary.rpc.test' && $this->primaryDown) {
                return Http::response('bad gateway', 502);
            }
            return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $this->answer($method, $request['params'] ?? [])]);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function answer(string $method, array $params): mixed
    {
        return match ($method) {
            'eth_chainId' => '0x' . dechex($this->chainId),
            'eth_blockNumber' => '0x' . dechex($this->head),
            'eth_getBlockByNumber' => $params[0] === 'finalized'
                ? ['number' => '0x' . dechex($this->finalized), 'hash' => $this->blockHash($this->finalized)]
                : ['number' => $params[0], 'timestamp' => '0x' . dechex(now()->timestamp), 'hash' => $this->blockHash(hexdec($params[0]))],
            'eth_getLogs' => array_values(array_filter($this->logs, function ($l) use ($params) {
                $f = $params[0];
                $n = hexdec($l['blockNumber']);
                return $n >= hexdec($f['fromBlock']) && $n <= hexdec($f['toBlock'])
                    && strtolower($l['address']) === strtolower($f['address'])
                    && strtolower($l['topics'][2]) === strtolower($f['topics'][2]);
            })),
            'eth_getTransactionReceipt' => array_key_exists($params[0], $this->receipts)
                ? $this->receipts[$params[0]]
                : $this->receiptFor($params[0]),
            default => null,
        };
    }

    private function blockHash(int $n): string { return '0x' . str_pad(dechex($n), 64, 'a', STR_PAD_LEFT); }

    /** Someone sends $amount USDT (a decimal string, up to 18 decimals) to $to in block $block. */
    private function pay(string $amount, int $block, string $to = self::OURS, string $contract = Usdt::CONTRACT): string
    {
        [$int, $frac] = array_pad(explode('.', $amount), 2, '');
        $base = bcadd(bcmul($int, bcpow('10', '18')), str_pad($frac, 18, '0'));
        $hex = '';
        for ($v = $base; bccomp($v, '0') > 0; $v = bcdiv($v, '16', 0)) $hex = dechex((int) bcmod($v, '16')) . $hex;
        $tx = '0x' . bin2hex(random_bytes(32));
        $this->logs[] = [
            'address' => $contract, 'blockNumber' => '0x' . dechex($block), 'blockHash' => $this->blockHash($block),
            'transactionHash' => $tx, 'logIndex' => '0x3', 'removed' => false,
            'topics' => [Usdt::TRANSFER_TOPIC, Usdt::addressToTopic(self::PAYER), Usdt::addressToTopic($to)],
            'data' => '0x' . str_pad($hex ?: '0', 64, '0', STR_PAD_LEFT),
        ];
        return $tx;
    }

    private function receiptFor(string $tx): ?array
    {
        foreach ($this->logs as $l) {
            if ($l['transactionHash'] === $tx) {
                return ['transactionHash' => $tx, 'blockHash' => $l['blockHash'], 'blockNumber' => $l['blockNumber'], 'status' => '0x1', 'logs' => [$l]];
            }
        }
        return null;
    }

    private function tenant(int $usdtMicro = 0): Tenant
    {
        $plan = Plan::firstOrCreate(['slug' => 'free'], ['name' => 'Free', 'price_monthly' => 0, 'max_chatbots' => 1, 'max_tokens_monthly' => 1000, 'is_active' => true, 'sort_order' => 0]);
        $tenant = Tenant::create(['slug' => 't-' . Str::random(8), 'name' => 'Shop', 'email' => Str::random(10) . '@example.test',
            'plan_id' => $plan->id, 'schema_name' => 'placeholder', 'status' => 'active', 'language' => 'en',
            'quota_period_started_at' => now()->subDays(10)]);
        $schema = 'tenant_' . str_replace('-', '', $tenant->id);
        $tenant->update(['schema_name' => $schema]);
        app(TenantService::class)->createSchema($schema);
        if ($usdtMicro) app(WalletService::class)->applyUsdt($tenant, $usdtMicro, 'admin_adjustment');
        return $tenant->fresh();
    }

    private function scan(): array { return app(UsdtScanner::class)->scan(); }

    private function deposit(Tenant $tenant, string $amount = '20'): CryptoDeposit
    {
        return app(UsdtDeposits::class)->create($tenant, Usdt::toMicro($amount), null);
    }

    // ── Amounts ────────────────────────────────────────────────────────

    public function test_amounts_are_exact_integers_end_to_end(): void
    {
        $this->assertSame(19_990_000, Usdt::toMicro('19.99'));
        $this->assertSame(19_990_000, Usdt::toMicro('۱۹٫۹۹'));
        $this->assertSame(20_004_213, Usdt::toMicro('20.004213'));
        $this->assertNull(Usdt::toMicro('20.0042131'), 'more than six decimals is refused, not rounded');
        $this->assertNull(Usdt::toMicro('-1'));
        $this->assertSame('20.004213', Usdt::plain(20_004_213));
        $this->assertSame('19.99', Usdt::plain(19_990_000));
        $this->assertSame('5.00', Usdt::plain(5_000_000));
        $this->assertSame('20004213000000000000', Usdt::microToBase(20_004_213));
        $this->assertSame(20_004_213, Usdt::baseToMicro('20004213000000000000'));
        $this->assertTrue(Usdt::isWholeMicro('20004213000000000000'));
        $this->assertFalse(Usdt::isWholeMicro('20004213000000000001'));
        $this->assertSame('282890146210807530000', Usdt::hexToDec('0xf55e3b129997fc210'), 'a real 282.89 USDT transfer');
        $this->assertSame('115792089237316195423570985008687907853269984665640564039457584007913129639935',
            Usdt::hexToDec('0x' . str_repeat('f', 64)), 'a full uint256 does not overflow');
    }

    // ── Requests ───────────────────────────────────────────────────────

    public function test_every_request_gets_its_own_exact_amount(): void
    {
        $a = $this->deposit($this->tenant());
        $b = $this->deposit($this->tenant());
        $this->assertNotSame($a->amount_micro, $b->amount_micro);
        foreach ([$a, $b] as $d) {
            $this->assertGreaterThan(20_000_000, $d->amount_micro);
            $this->assertLessThan(20_010_000, $d->amount_micro, 'the tail is under one cent');
            $this->assertSame(self::OURS, $d->address);
        }
    }

    public function test_a_request_is_refused_when_disabled_or_out_of_range(): void
    {
        $tenant = $this->tenant();
        foreach (['1' => 'amount_out_of_range', '20000' => 'amount_out_of_range'] as $amount => $reason) {
            try { $this->deposit($tenant, $amount); $this->fail(); } catch (\DomainException $e) { $this->assertSame($reason, $e->getMessage()); }
        }
        Settings::set('crypto.usdt_enabled', false);
        try { $this->deposit($tenant); $this->fail(); } catch (\DomainException $e) { $this->assertSame('disabled', $e->getMessage()); }
    }

    // ── Scanning and crediting ─────────────────────────────────────────

    public function test_an_exact_payment_is_credited_once_and_only_after_its_block_is_final(): void
    {
        $tenant = $this->tenant();
        $deposit = $this->deposit($tenant);
        $this->scan();                                                  // first run sets the checkpoint

        $tx = $this->pay(Usdt::plain($deposit->amount_micro), 999);    // in a block not yet final (finalized = 997)
        $this->scan();
        $transfer = CryptoTransfer::sole();
        $this->assertSame('detected', $transfer->status);
        $this->assertSame(0, (int) $tenant->fresh()->wallet_balance_usdt, 'not before finality');
        $this->assertNotNull(app(UsdtDeposits::class)->pendingTransfer($deposit), 'the page can show "detected"');

        $this->finalized = 1000;
        $r = $this->scan();
        $this->assertSame(1, $r['credited']);
        $this->scan();
        $this->scan();

        $this->assertSame($deposit->amount_micro, (int) $tenant->fresh()->wallet_balance_usdt);
        $this->assertSame(1, UsdtWalletTransaction::count(), 'credited exactly once');
        $transfer->refresh();
        $this->assertSame('credited', $transfer->status);
        $this->assertSame($tx, $transfer->tx_hash);
        $this->assertSame(999, $transfer->block_number);
        $this->assertSame(self::PAYER, $transfer->from_address);
        $this->assertSame('paid', $deposit->fresh()->status);
        $this->assertSame((string) $tenant->id, (string) $transfer->tenant_id);
        $this->assertSame(0, (int) WalletTransaction::where('tenant_id', $tenant->id)->count(), 'the Toman wallet is untouched');
    }

    public function test_a_payment_that_matches_no_request_waits_for_an_admin(): void
    {
        $tenant = $this->tenant();
        $deposit = $this->deposit($tenant);
        $this->scan();
        $this->pay('20', 998);                                          // the round amount, not the exact one
        $this->pay(Usdt::plain($deposit->amount_micro) . '000000000001', 998);   // right amount + 1 wei
        $this->finalized = 1000;
        $this->scan();

        $this->assertSame(2, CryptoTransfer::where('status', 'unmatched')->count());
        $this->assertSame(0, (int) $tenant->fresh()->wallet_balance_usdt);
        $this->assertSame('pending', $deposit->fresh()->status);
    }

    public function test_a_reorged_transfer_is_never_credited(): void
    {
        $tenant = $this->tenant();
        $deposit = $this->deposit($tenant);
        $this->scan();
        $tx = $this->pay(Usdt::plain($deposit->amount_micro), 999);
        $this->scan();
        // The block it was in is replaced: the receipt now points elsewhere.
        $this->receipts[$tx] = null;
        $this->finalized = 1000;
        $this->scan();

        $this->assertSame('reorged', CryptoTransfer::sole()->status);
        $this->assertSame(0, (int) $tenant->fresh()->wallet_balance_usdt);
        $this->assertSame('pending', $deposit->fresh()->status);
    }

    public function test_another_token_or_another_recipient_is_not_seen_at_all(): void
    {
        $tenant = $this->tenant();
        $deposit = $this->deposit($tenant);
        $this->scan();
        $this->pay(Usdt::plain($deposit->amount_micro), 998, contract: '0x9999999999999999999999999999999999999999');
        $this->pay(Usdt::plain($deposit->amount_micro), 998, to: '0x3333333333333333333333333333333333333333');
        $this->finalized = 1000;
        $this->scan();
        $this->assertSame(0, CryptoTransfer::count());
    }

    public function test_an_rpc_on_the_wrong_chain_is_refused(): void
    {
        $this->chainId = 97;   // BSC testnet
        $this->tenant();
        $this->expectException(RpcUnavailable::class);
        try {
            $this->scan();
        } finally {
            $this->assertStringContainsString('wrong chain', (string) \DB::table('chain_checkpoints')->value('last_error'));
        }
    }

    public function test_the_fallback_rpc_takes_over_when_the_primary_is_down(): void
    {
        $tenant = $this->tenant();
        $deposit = $this->deposit($tenant);
        $this->scan();
        $this->primaryDown = true;
        $this->pay(Usdt::plain($deposit->amount_micro), 998);
        $this->finalized = 1000;
        $this->scan();

        $this->assertSame($deposit->amount_micro, (int) $tenant->fresh()->wallet_balance_usdt);
        $this->assertSame('fallback.rpc.test', \DB::table('chain_checkpoints')->value('provider'));
    }

    public function test_a_late_payment_inside_the_grace_window_is_still_credited(): void
    {
        $tenant = $this->tenant();
        $deposit = $this->deposit($tenant);
        $this->scan();
        Carbon::setTestNow(now()->addHours(3));                         // the request expired after 60 minutes
        $this->scan();
        $this->assertSame('expired', $deposit->fresh()->status);

        $this->pay(Usdt::plain($deposit->amount_micro), 998);
        $this->finalized = 1000;
        $this->scan();
        $this->assertSame('paid', $deposit->fresh()->status);
        $this->assertSame($deposit->amount_micro, (int) $tenant->fresh()->wallet_balance_usdt);
    }

    public function test_a_scanner_that_was_down_catches_up_from_its_checkpoint(): void
    {
        $tenant = $this->tenant();
        $deposit = $this->deposit($tenant);
        $this->scan();
        // 3,000 blocks later — the payment is in the middle of the gap.
        $this->head = 4000;
        $this->finalized = 3997;
        $this->pay(Usdt::plain($deposit->amount_micro), 2500);
        $r = $this->scan();

        $this->assertSame(1, $r['credited']);
        $this->assertSame(3997, (int) \DB::table('chain_checkpoints')->value('last_scanned_block'));
    }

    // ── Spending the USDT wallet ───────────────────────────────────────

    public function test_tokens_bought_in_usdt_come_out_of_the_usdt_wallet_only(): void
    {
        $tenant = $this->tenant(10_000_000);
        app(WalletService::class)->applyCompletedTransaction($tenant, 'topup', 500000);
        Plan::where('id', $tenant->plan_id)->update(['can_purchase_tokens' => true]);
        $package = TokenPackage::create(['name' => 'Pack', 'token_amount' => 1000, 'price_toman' => 200000, 'price_usdt' => 4_500_000, 'is_active' => true]);

        app(PurchaseService::class)->buyTokens($tenant, $package, 'USDT');
        $fresh = $tenant->fresh();
        $this->assertSame(5_500_000, (int) $fresh->wallet_balance_usdt);
        $this->assertSame(500000, (int) $fresh->wallet_balance_toman, 'no conversion, no Toman charge');
        $this->assertSame(1000, (int) $fresh->bonus_tokens);

        $package->update(['price_usdt' => null]);
        $this->expectException(\DomainException::class);
        app(PurchaseService::class)->buyTokens($tenant, $package, 'USDT');
    }

    public function test_a_plan_paid_in_usdt_renews_from_the_usdt_wallet(): void
    {
        $tenant = $this->tenant(100_000_000);
        $pro = Plan::create(['name' => 'Pro', 'slug' => 'pro-' . Str::random(4), 'price_monthly' => 990000, 'price_monthly_usdt' => 19_000_000,
            'max_chatbots' => 3, 'max_tokens_monthly' => 1000, 'is_active' => true, 'is_public' => true, 'sort_order' => 1]);

        $charged = app(BillingService::class)->upgradePlan($tenant, $pro, 'USDT');
        $this->assertSame(intdiv(19_000_000 * 20, 30), $charged);   // 20 of 30 days left, in micro-USDT
        $fresh = $tenant->fresh();
        $this->assertSame('USDT', $fresh->billing_currency);
        $this->assertSame(100_000_000 - $charged, (int) $fresh->wallet_balance_usdt);

        Carbon::setTestNow($fresh->plan_paid_until->copy()->subDays(2));
        app(BillingService::class)->run();
        $invoice = Invoice::sole();
        $this->assertSame('USDT', $invoice->currency);
        $this->assertSame(19_000_000, $invoice->amount);

        Carbon::setTestNow($invoice->due_at->copy()->addHour());
        app(BillingService::class)->run();
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertNotNull($invoice->fresh()->usdt_wallet_transaction_id);
        $this->assertSame(100_000_000 - $charged - 19_000_000, (int) $tenant->fresh()->wallet_balance_usdt);
    }

    public function test_a_usdt_purchase_without_the_balance_changes_nothing(): void
    {
        $tenant = $this->tenant(1_000_000);
        $pro = Plan::create(['name' => 'Pro', 'slug' => 'pro-' . Str::random(4), 'price_monthly' => 990000, 'price_monthly_usdt' => 19_000_000,
            'max_chatbots' => 3, 'max_tokens_monthly' => 1000, 'is_active' => true, 'is_public' => true, 'sort_order' => 1]);
        $this->expectException(InsufficientBalance::class);
        try {
            app(BillingService::class)->upgradePlan($tenant, $pro, 'USDT');
        } finally {
            $this->assertSame(1_000_000, (int) $tenant->fresh()->wallet_balance_usdt);
            $this->assertNotSame($pro->id, $tenant->fresh()->plan_id);
        }
    }

    public function test_switching_the_renewal_wallet_reissues_the_open_invoice(): void
    {
        $tenant = $this->tenant(100_000_000);
        $pro = Plan::create(['name' => 'Pro', 'slug' => 'pro-' . Str::random(4), 'price_monthly' => 990000, 'price_monthly_usdt' => 19_000_000,
            'max_chatbots' => 3, 'max_tokens_monthly' => 1000, 'is_active' => true, 'is_public' => true, 'sort_order' => 1]);
        $tenant->update(['plan_id' => $pro->id, 'plan_paid_until' => now()->addDays(2)]);
        app(BillingService::class)->run();
        $this->assertSame('IRT', Invoice::sole()->currency);

        app(BillingService::class)->setBillingCurrency($tenant, 'USDT');
        app(BillingService::class)->run();
        $this->assertSame('currency_changed', Invoice::where('currency', 'IRT')->sole()->void_reason);
        $this->assertSame(19_000_000, Invoice::where('status', 'open')->sole()->amount);
    }

    // ── Pages ──────────────────────────────────────────────────────────

    private function portalUser(Tenant $tenant): \App\Models\User
    {
        $user = \App\Models\User::create(['tenant_id' => $tenant->id, 'email' => Str::random(10) . '@example.test', 'password' => bcrypt('x'),
            'password_hash' => bcrypt('x'), 'name' => 'Owner', 'role' => 'owner', 'email_verified_at' => now(), 'locale' => 'en']);
        $this->actingAs($user, 'web');
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('customer'));
        return $user;
    }

    private function admin(): \App\Models\User
    {
        $user = \App\Models\User::create(['email' => Str::random(10) . '@example.test', 'password' => bcrypt('x'), 'password_hash' => bcrypt('x'),
            'name' => 'Admin', 'role' => 'owner', 'email_verified_at' => now(), 'locale' => 'en']);
        $user->platform_role = 'admin';
        $user->platform_is_active = true;
        $user->save();
        $this->actingAs($user, 'web');
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
        return $user;
    }

    public function test_the_customer_creates_a_request_and_sees_exactly_what_to_send(): void
    {
        $tenant = $this->tenant();
        $other = $this->tenant();
        $othersDeposit = $this->deposit($other, '30');
        $this->portalUser($tenant);

        \Livewire\Livewire::test(\App\Filament\Customer\Pages\UsdtWallet::class)
            ->assertOk()
            ->assertSee(__('usdt.topup_heading'))
            ->fillForm(['amount' => '25'])
            ->call('createDeposit')
            ->assertSee(self::OURS)
            ->assertSee('BEP20')
            ->assertDontSee(Usdt::plain($othersDeposit->amount_micro));

        $mine = CryptoDeposit::where('tenant_id', $tenant->id)->sole();
        $this->assertSame(25_000_000, $mine->requested_micro);
        \Livewire\Livewire::test(\App\Filament\Customer\Pages\UsdtWallet::class)->assertSee(Usdt::plain($mine->amount_micro));
    }

    public function test_the_customer_page_says_so_when_usdt_is_off(): void
    {
        Settings::set('crypto.usdt_enabled', false);
        $this->portalUser($this->tenant());
        \Livewire\Livewire::test(\App\Filament\Customer\Pages\UsdtWallet::class)->assertSee(__('usdt.not_available'))->assertDontSee(self::OURS);
    }

    public function test_an_admin_credits_an_unmatched_payment_with_a_reason_and_it_is_recorded(): void
    {
        $tenant = $this->tenant();
        $this->scan();
        $this->pay('12.5', 998);
        $this->finalized = 1000;
        $this->scan();
        $transfer = CryptoTransfer::where('status', 'unmatched')->sole();

        $this->admin();
        \Livewire\Livewire::test(\App\Filament\Resources\CryptoTransferResource\Pages\ListCryptoTransfers::class)
            ->assertOk()
            ->callTableAction('assign', $transfer, ['tenant_id' => $tenant->id, 'reason' => ''])
            ->assertHasTableActionErrors(['reason']);
        $this->assertSame(0, (int) $tenant->fresh()->wallet_balance_usdt);

        \Livewire\Livewire::test(\App\Filament\Resources\CryptoTransferResource\Pages\ListCryptoTransfers::class)
            ->callTableAction('assign', $transfer, ['tenant_id' => $tenant->id, 'reason' => 'customer sent 12.5 instead of the exact amount, ticket #7']);
        $this->assertSame(12_500_000, (int) $tenant->fresh()->wallet_balance_usdt);
        $this->assertSame('credited', $transfer->fresh()->status);
        $row = \DB::table('platform_activity_log')->where('action', 'usdt_transfer_assigned')->sole();
        $this->assertStringContainsString('ticket #7', $row->after);

        // Credited once: a second assign does nothing.
        try { app(\App\Services\Crypto\TransferReview::class)->assign($transfer->fresh(), $tenant, 'again'); $this->fail(); }
        catch (\DomainException) {}
        $this->assertSame(12_500_000, (int) $tenant->fresh()->wallet_balance_usdt);
    }

    public function test_an_admin_usdt_adjustment_goes_through_the_ledger_and_the_activity_log(): void
    {
        $tenant = $this->tenant();
        $this->admin();
        \Livewire\Livewire::test(\App\Filament\Resources\UsdtWalletTransactionResource\Pages\CreateUsdtWalletTransaction::class)
            ->fillForm(['tenant_id' => $tenant->id, 'amount' => '7.25', 'description' => 'goodwill credit'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(7_250_000, (int) $tenant->fresh()->wallet_balance_usdt);
        $this->assertSame(7_250_000, (int) UsdtWalletTransaction::where('tenant_id', $tenant->id)->sum('amount_micro'));
        $this->assertStringContainsString('goodwill credit',
            \DB::table('platform_activity_log')->where('action', 'usdt_wallet_adjusted')->value('after'));
    }

    public function test_the_admin_pages_render(): void
    {
        $this->admin();
        foreach ([\App\Filament\Resources\CryptoTransferResource::class, \App\Filament\Resources\CryptoDepositResource::class,
                  \App\Filament\Resources\UsdtWalletTransactionResource::class, \App\Filament\Resources\InvoiceResource::class] as $resource) {
            $this->get($resource::getUrl('index'))->assertOk();
        }
    }
}
