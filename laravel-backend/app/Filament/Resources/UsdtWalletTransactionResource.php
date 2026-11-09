<?php
namespace App\Filament\Resources;

use App\Filament\Resources\UsdtWalletTransactionResource\Pages;
use App\Models\{Tenant, UsdtWalletTransaction};
use App\Support\{Jalali, PlatformAccess, Usdt};
use Filament\Forms\Components\{Select, Textarea, TextInput};
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Columns\{BadgeColumn, TextColumn};
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Every tenant's USDT wallet ledger. Append-only, like the Toman ledger:
 * the only write is a manual adjustment, through WalletService, recorded
 * in the activity log (CreateUsdtWalletTransaction).
 */
class UsdtWalletTransactionResource extends Resource {
    public static function canViewAny(): bool { return PlatformAccess::allows('wallet_ledger'); }
    public static function canView($record): bool { return PlatformAccess::allows('wallet_ledger'); }
    public static function canCreate(): bool { return PlatformAccess::allows('wallet_ledger'); }
    public static function canEdit($record): bool { return false; }
    public static function canDelete($record): bool { return false; }
    public static function canDeleteAny(): bool { return false; }
    public static function shouldRegisterNavigation(): bool { return PlatformAccess::allows('wallet_ledger'); }

    protected static ?string $model = UsdtWalletTransaction::class;
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';
    protected static ?int $navigationSort = 8;

    public static function getNavigationLabel(): string { return __('usdt.admin_ledger'); }
    public static function getNavigationGroup(): ?string { return __('panel.nav_group_finance'); }
    public static function getModelLabel(): string { return __('wallet.transaction_singular'); }
    public static function getPluralModelLabel(): string { return __('usdt.admin_ledger'); }

    public static function form(Form $form): Form {
        return $form->schema([
            Select::make('tenant_id')->label(__('panel.tenant'))->options(fn () => Tenant::orderBy('name')->pluck('name', 'id'))->searchable()->required(),
            TextInput::make('amount')->label(__('usdt.adjust_amount'))->suffix('USDT')->required()
                ->helperText(__('usdt.adjust_amount_help'))
                ->rule(fn () => function (string $attribute, $value, \Closure $fail) {
                    $v = trim((string) $value);
                    if (Usdt::toMicro(ltrim($v, '-')) === null || (int) Usdt::toMicro(ltrim($v, '-')) === 0) $fail(__('usdt.amount_invalid'));
                }),
            Textarea::make('description')->label(__('common.description'))->required()->placeholder(__('wallet.adjustment_reason_placeholder')),
        ]);
    }

    public static function table(Table $table): Table {
        return $table
            ->columns([
                TextColumn::make('created_at')->label(__('wallet.date'))->formatStateUsing(fn ($state) => Jalali::dateTime($state))->sortable(),
                TextColumn::make('tenant.name')->label(__('panel.tenant'))->searchable(),
                BadgeColumn::make('type')->label(__('wallet.type'))
                    ->colors(['success' => 'deposit', 'danger' => 'plan_charge', 'warning' => 'admin_adjustment', 'gray' => 'refund'])
                    ->formatStateUsing(fn (string $state) => __('usdt.type_' . $state)),
                TextColumn::make('amount_micro')->label(__('usdt.amount_col'))->formatStateUsing(fn (int $state) => Usdt::format($state))
                    ->color(fn (int $state) => $state >= 0 ? 'success' : 'danger'),
                TextColumn::make('balance_after_micro')->label(__('wallet.balance_after'))->formatStateUsing(fn (int $state) => Usdt::format($state)),
                TextColumn::make('description')->label(__('common.description'))->limit(40),
            ])
            ->filters([
                SelectFilter::make('type')->label(__('wallet.type'))->options(collect(['deposit', 'plan_charge', 'admin_adjustment', 'refund'])
                    ->mapWithKeys(fn ($s) => [$s => __('usdt.type_' . $s)])->all()),
                SelectFilter::make('tenant_id')->relationship('tenant', 'name')->label(__('panel.tenant'))->searchable(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array {
        return [
            'index'  => Pages\ListUsdtWalletTransactions::route('/'),
            'create' => Pages\CreateUsdtWalletTransaction::route('/create'),
        ];
    }
}
