<?php
namespace App\Filament\Customer\Pages;

use App\Models\WalletTransaction;
use App\Services\PaymentService;
use Filament\Pages\Page;
use Filament\Forms\Form;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Table;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Columns\{TextColumn, BadgeColumn};
use Filament\Notifications\Notification;
use App\Support\Jalali;
use App\Support\Money;

class Wallet extends Page implements HasForms, HasTable {
    use InteractsWithForms, InteractsWithTable;

    protected static string $view = 'filament.customer.pages.wallet';
    protected static ?string $navigationIcon = 'heroicon-o-wallet';

    public static function getNavigationLabel(): string { return __('wallet.nav'); }
    public function getTitle(): string { return __('wallet.nav'); }

    public ?array $data = [];

    public function mount(): void {
        $this->form->fill(['amount_toman' => 500000]);

        // Zarinpal redirected back here after payment — surface the outcome.
        $topup = request()->query('topup');
        if ($topup === 'success') {
            Notification::make()->title(__('wallet.payment_success'))->success()->send();
        } elseif ($topup === 'failed') {
            Notification::make()
                ->title(__('wallet.payment_failed'))
                ->body(request()->query('message', ''))
                ->danger()
                ->send();
        }
    }

    public function form(Form $form): Form {
        return $form->schema([
            TextInput::make('amount_toman')
                ->label(__('wallet.topup_amount'))
                ->numeric()
                ->required()
                ->minValue(10000)
                ->step(10000),
        ])->statePath('data');
    }

    public function getWalletBalance(): int {
        return (int) (auth()->user()->tenant->wallet_balance_toman ?? 0);
    }

    public function topup(): void {
        $state  = $this->form->getState();
        $tenant = auth()->user()->tenant;

        $result = app(PaymentService::class)->initTopup(
            $tenant,
            (int) $state['amount_toman'],
            route('payments.zarinpal.callback'),
        );

        if ($result['ok']) {
            $this->redirect($result['redirect_url']);
        } else {
            Notification::make()
                ->title(__('wallet.topup_init_failed'))
                ->body($result['message'] ?? '')
                ->danger()
                ->send();
        }
    }

    public function table(Table $table): Table {
        return $table
            ->query(fn () => WalletTransaction::query()->where('tenant_id', auth()->user()->tenant_id))
            ->columns([
                TextColumn::make('created_at')->label(__('wallet.date'))->formatStateUsing(fn ($state) => Jalali::dateTime($state)),
                BadgeColumn::make('type')->label(__('wallet.type'))->colors([
                    'success' => 'topup',
                    'danger'  => 'plan_charge',
                    'warning' => 'admin_adjustment',
                    'gray'    => 'refund',
                ])->formatStateUsing(fn (string $state) => match ($state) {
                    'topup' => __('wallet.type_topup'), 'plan_charge' => __('wallet.type_plan_charge'),
                    'admin_adjustment' => __('wallet.type_admin_adjustment'), 'refund' => __('wallet.type_refund'),
                    default => $state,
                }),
                TextColumn::make('amount_toman')->label(__('wallet.amount_toman_col'))
                    ->formatStateUsing(fn (int $state) => number_format($state))
                    ->color(fn (int $state) => $state >= 0 ? 'success' : 'danger'),
                BadgeColumn::make('status')->label(__('common.status'))->colors([
                    'success' => 'completed',
                    'warning' => 'pending',
                    'danger'  => ['failed', 'reversed'],
                ])->formatStateUsing(fn (string $state) => match ($state) {
                    'completed' => __('wallet.status_completed'), 'pending' => __('wallet.status_pending'),
                    'failed' => __('wallet.status_failed'), 'reversed' => __('wallet.status_reversed'),
                    default => $state,
                }),
                TextColumn::make('description')->label(__('common.description'))->limit(40),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
