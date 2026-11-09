<?php
namespace App\Filament\Customer\Pages;

use App\Models\Crypto\{CryptoDeposit, CryptoTransfer};
use App\Models\{Tenant, UsdtWalletTransaction};
use App\Services\Crypto\UsdtDeposits;
use App\Support\{Jalali, Settings, Usdt};
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\{BadgeColumn, TextColumn};
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * The tenant's USDT wallet: its balance, topping it up with USDT on BNB
 * Smart Chain (BEP20), and its ledger. A top-up is a request for an exact
 * amount to the platform's address (UsdtDeposits); the page polls while one
 * is open and shows the transaction once the scanner has seen it.
 */
class UsdtWallet extends Page implements HasForms, HasTable
{
    use InteractsWithForms, InteractsWithTable;

    protected static string $view = 'filament.customer.pages.usdt-wallet';
    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';
    protected static ?int $navigationSort = 1;

    public ?array $data = [];

    public static function getNavigationLabel(): string { return __('usdt.nav'); }
    public static function getNavigationGroup(): ?string { return __('panel.nav_group_customer_wallet'); }
    public function getTitle(): string { return __('usdt.nav'); }
    public function getSubheading(): ?string { return __('usdt.subheading'); }

    private function tenant(): Tenant { return Tenant::findOrFail(auth()->user()->tenant_id); }

    public function mount(): void
    {
        $this->form->fill(['amount' => (string) max(1, (int) Settings::get('crypto.usdt_min_deposit')) * 4]);
    }

    public function isEnabled(): bool { return UsdtDeposits::enabled(); }

    public function getBalance(): string { return Usdt::format((int) $this->tenant()->wallet_balance_usdt); }

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('amount')
                ->label(__('usdt.amount_label'))
                ->suffix('USDT')
                ->required()
                ->helperText(__('usdt.amount_help', [
                    'min' => (int) Settings::get('crypto.usdt_min_deposit'),
                    'max' => (int) Settings::get('crypto.usdt_max_deposit'),
                ]))
                ->rule(fn () => function (string $attribute, $value, \Closure $fail) {
                    if (Usdt::toMicro((string) $value) === null) $fail(__('usdt.amount_invalid'));
                }),
        ])->statePath('data');
    }

    /** The open request, if any — what the payment card shows. */
    public function getDeposit(): ?CryptoDeposit
    {
        return app(UsdtDeposits::class)->open($this->tenant());
    }

    /** A transfer for the open request seen on chain, not final yet. */
    public function getDetected(): ?CryptoTransfer
    {
        $deposit = $this->getDeposit();
        return $deposit ? app(UsdtDeposits::class)->pendingTransfer($deposit) : null;
    }

    /** The most recent credited deposit in the last hour — so the card can say "received" with its transaction. */
    public function getRecentlyPaid(): ?CryptoDeposit
    {
        return CryptoDeposit::where('tenant_id', $this->tenant()->id)->where('status', 'paid')
            ->where('paid_at', '>=', now()->subHour())->latest('paid_at')->with('transfer')->first();
    }

    public function createDeposit(): void
    {
        $micro = Usdt::toMicro((string) ($this->form->getState()['amount'] ?? ''));
        try {
            app(UsdtDeposits::class)->create($this->tenant(), (int) $micro, auth()->id());
        } catch (\DomainException $e) {
            Notification::make()->title(__('usdt.error_' . $e->getMessage(), [
                'min' => (int) Settings::get('crypto.usdt_min_deposit'),
                'max' => (int) Settings::get('crypto.usdt_max_deposit'),
            ]))->danger()->send();
        }
    }

    public function cancelDeposit(): void
    {
        if ($deposit = $this->getDeposit()) {
            app(UsdtDeposits::class)->cancel($deposit);
        }
    }

    public function table(Table $table): Table
    {
        return $table
            ->striped()
            ->emptyStateIcon('heroicon-o-currency-dollar')
            ->emptyStateHeading(__('usdt.empty_title'))
            ->emptyStateDescription(__('usdt.empty_desc'))
            ->query(fn () => UsdtWalletTransaction::query()->where('tenant_id', auth()->user()->tenant_id)->with('transfer'))
            ->columns([
                TextColumn::make('created_at')->label(__('wallet.date'))->formatStateUsing(fn ($state) => Jalali::dateTime($state)),
                BadgeColumn::make('type')->label(__('wallet.type'))
                    ->colors(['success' => 'deposit', 'danger' => 'plan_charge', 'warning' => 'admin_adjustment', 'gray' => 'refund'])
                    ->formatStateUsing(fn (string $state) => __('usdt.type_' . $state)),
                TextColumn::make('amount_micro')->label(__('usdt.amount_col'))
                    ->formatStateUsing(fn (int $state) => Usdt::format($state))
                    ->color(fn (int $state) => $state >= 0 ? 'success' : 'danger'),
                TextColumn::make('balance_after_micro')->label(__('wallet.balance_after'))->formatStateUsing(fn (int $state) => Usdt::format($state)),
                TextColumn::make('transfer.tx_hash')->label(__('usdt.tx'))->placeholder('—')
                    ->formatStateUsing(fn ($state) => substr((string) $state, 0, 10) . '…' . substr((string) $state, -6))
                    ->url(fn (UsdtWalletTransaction $record) => $record->transfer ? Usdt::txUrl($record->transfer->tx_hash) : null, shouldOpenInNewTab: true),
                TextColumn::make('description')->label(__('common.description'))->limit(40),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
