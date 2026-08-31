<?php
namespace App\Filament\Customer\Pages;

use App\Models\TokenPackage;
use App\Models\Tenant;
use App\Services\WalletService;
use Filament\Pages\Page;
use Filament\Tables\Table;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Actions\Action;
use Filament\Notifications\Notification;

// bonus_tokens purchased here only kick in once the tenant's plan quota for
// the month is exhausted (see Tenant::isTokenQuotaExceeded() and
// TenantService::incrementUsage()) — this is a top-up, not a plan upgrade.
class BuyTokens extends Page implements HasTable {
    use InteractsWithTable;

    protected static string $view = 'filament.customer.pages.buy-tokens';
    protected static ?string $navigationIcon = 'heroicon-o-bolt';
    protected static ?string $navigationLabel = 'خرید توکن';
    protected static ?string $title = 'خرید توکن اضافه';

    public function getWalletBalance(): int {
        return (int) (auth()->user()->tenant->wallet_balance_toman ?? 0);
    }

    public function getBonusBalance(): int {
        return (int) (auth()->user()->tenant->bonus_tokens ?? 0);
    }

    public function table(Table $table): Table {
        return $table
            ->query(fn () => TokenPackage::query()->active())
            ->columns([
                TextColumn::make('name')->label('نام بسته'),
                TextColumn::make('chatbot_type')->label('نوع چت‌بات')->badge()->placeholder('همه انواع'),
                TextColumn::make('token_amount')->label('تعداد توکن')
                    ->formatStateUsing(fn (int $state) => number_format($state)),
                TextColumn::make('price_toman')->label('قیمت')
                    ->formatStateUsing(fn (int $state) => number_format($state) . ' تومان'),
            ])
            ->actions([
                Action::make('buy')
                    ->label('خرید')
                    ->icon('heroicon-o-shopping-cart')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription(fn (TokenPackage $record) => "مبلغ " . number_format($record->price_toman) . " تومان از کیف پول شما کسر می‌شود و " . number_format($record->token_amount) . " توکن به موجودی شما اضافه می‌شود.")
                    ->action(fn (TokenPackage $record) => $this->buy($record)),
            ])
            ->defaultSort('price_toman');
    }

    private function buy(TokenPackage $package): void {
        $tenant = Tenant::where('id', auth()->user()->tenant_id)->lockForUpdate()->first();

        if ($tenant->wallet_balance_toman < $package->price_toman) {
            Notification::make()
                ->title('موجودی کیف پول کافی نیست')
                ->body('لطفاً ابتدا کیف پول خود را شارژ کنید.')
                ->danger()
                ->send();
            return;
        }

        app(WalletService::class)->applyCompletedTransaction(
            $tenant, 'plan_charge', -$package->price_toman,
            ['description' => "خرید توکن: {$package->name} ({$package->token_amount} توکن)"],
        );

        $tenant->increment('bonus_tokens', $package->token_amount);

        Notification::make()->title('توکن با موفقیت خریداری شد')->success()->send();
    }
}
