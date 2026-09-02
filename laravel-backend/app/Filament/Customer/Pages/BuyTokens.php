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
use App\Support\Money;

// bonus_tokens purchased here only kick in once the tenant's plan quota for
// the month is exhausted (see Tenant::isTokenQuotaExceeded() and
// TenantService::incrementUsage()) — this is a top-up, not a plan upgrade.
class BuyTokens extends Page implements HasTable {
    use InteractsWithTable;

    protected static string $view = 'filament.customer.pages.buy-tokens';
    protected static ?string $navigationIcon = 'heroicon-o-bolt';

    public static function getNavigationLabel(): string { return __('plan.buy_tokens_nav'); }
    public function getTitle(): string { return __('plan.buy_tokens_title'); }

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
                TextColumn::make('name')->label(__('plan.package_name')),
                TextColumn::make('chatbot_type')->label(__('chatbot.type'))->badge()->placeholder(__('plan.all_chatbot_types')),
                TextColumn::make('token_amount')->label(__('plan.token_amount'))
                    ->formatStateUsing(fn (int $state) => number_format($state)),
                TextColumn::make('price_toman')->label(__('plan.price'))
                    ->formatStateUsing(fn (int $state) => Money::toman($state)),
            ])
            ->actions([
                Action::make('buy')
                    ->label(__('plan.buy_action'))
                    ->icon('heroicon-o-shopping-cart')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription(fn (TokenPackage $record) => __('plan.buy_confirm_description', [
                        'price' => number_format($record->price_toman),
                        'amount' => number_format($record->token_amount),
                    ]))
                    ->action(fn (TokenPackage $record) => $this->buy($record)),
            ])
            ->defaultSort('price_toman');
    }

    private function buy(TokenPackage $package): void {
        $tenant = Tenant::where('id', auth()->user()->tenant_id)->lockForUpdate()->first();

        if ($tenant->wallet_balance_toman < $package->price_toman) {
            Notification::make()
                ->title(__('wallet.insufficient_balance'))
                ->body(__('wallet.topup_first'))
                ->danger()
                ->send();
            return;
        }

        app(WalletService::class)->applyCompletedTransaction(
            $tenant, 'plan_charge', -$package->price_toman,
            ['description' => __('plan.token_purchase_description', ['name' => $package->name, 'amount' => $package->token_amount])],
        );

        $tenant->increment('bonus_tokens', $package->token_amount);

        Notification::make()->title(__('plan.token_purchased_success'))->success()->send();
    }
}
