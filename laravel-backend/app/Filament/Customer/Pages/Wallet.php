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

class Wallet extends Page implements HasForms, HasTable {
    use InteractsWithForms, InteractsWithTable;

    protected static string $view = 'filament.customer.pages.wallet';
    protected static ?string $navigationIcon = 'heroicon-o-wallet';
    protected static ?string $navigationLabel = 'کیف پول';
    protected static ?string $title = 'کیف پول';

    public ?array $data = [];

    public function mount(): void {
        $this->form->fill(['amount_toman' => 500000]);

        // Zarinpal redirected back here after payment — surface the outcome.
        $topup = request()->query('topup');
        if ($topup === 'success') {
            Notification::make()->title('پرداخت با موفقیت انجام شد')->success()->send();
        } elseif ($topup === 'failed') {
            Notification::make()
                ->title('پرداخت ناموفق بود')
                ->body(request()->query('message', ''))
                ->danger()
                ->send();
        }
    }

    public function form(Form $form): Form {
        return $form->schema([
            TextInput::make('amount_toman')
                ->label('مبلغ شارژ (تومان)')
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
                ->title('شروع پرداخت ناموفق بود')
                ->body($result['message'] ?? '')
                ->danger()
                ->send();
        }
    }

    public function table(Table $table): Table {
        return $table
            ->query(fn () => WalletTransaction::query()->where('tenant_id', auth()->user()->tenant_id))
            ->columns([
                TextColumn::make('created_at')->label('تاریخ')->formatStateUsing(fn ($state) => Jalali::dateTime($state)),
                BadgeColumn::make('type')->label('نوع')->colors([
                    'success' => 'topup',
                    'danger'  => 'plan_charge',
                    'warning' => 'admin_adjustment',
                    'gray'    => 'refund',
                ]),
                TextColumn::make('amount_toman')->label('مبلغ (تومان)')
                    ->formatStateUsing(fn (int $state) => number_format($state))
                    ->color(fn (int $state) => $state >= 0 ? 'success' : 'danger'),
                BadgeColumn::make('status')->label('وضعیت')->colors([
                    'success' => 'completed',
                    'warning' => 'pending',
                    'danger'  => ['failed', 'reversed'],
                ]),
                TextColumn::make('description')->label('توضیحات')->limit(40),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
