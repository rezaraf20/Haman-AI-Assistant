<?php
namespace App\Filament\Resources;

use App\Filament\Resources\CryptoDepositResource\Pages;
use App\Models\Crypto\CryptoDeposit;
use App\Support\{Jalali, PlatformAccess, Usdt};
use Filament\Resources\Resource;
use Filament\Tables\Columns\{BadgeColumn, TextColumn};
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Tenants' USDT top-up requests — the exact amount each was told to send, and what became of it. Read-only. */
class CryptoDepositResource extends Resource {
    public static function canViewAny(): bool { return PlatformAccess::allows('wallet_ledger'); }
    public static function canView($record): bool { return PlatformAccess::allows('wallet_ledger'); }
    public static function canCreate(): bool { return false; }
    public static function canEdit($record): bool { return false; }
    public static function canDelete($record): bool { return false; }
    public static function canDeleteAny(): bool { return false; }
    public static function shouldRegisterNavigation(): bool { return PlatformAccess::allows('wallet_ledger'); }

    protected static ?string $model = CryptoDeposit::class;
    protected static ?string $navigationIcon = 'heroicon-o-inbox-arrow-down';
    protected static ?int $navigationSort = 8;

    public static function getNavigationLabel(): string { return __('usdt.admin_deposits'); }
    public static function getNavigationGroup(): ?string { return __('panel.nav_group_finance'); }
    public static function getModelLabel(): string { return __('usdt.deposit_singular'); }
    public static function getPluralModelLabel(): string { return __('usdt.admin_deposits'); }

    public static function table(Table $table): Table {
        return $table
            ->columns([
                TextColumn::make('created_at')->label(__('wallet.date'))->formatStateUsing(fn ($state) => Jalali::dateTime($state))->sortable(),
                TextColumn::make('tenant.name')->label(__('panel.tenant'))->searchable(),
                TextColumn::make('amount_micro')->label(__('usdt.exact_amount'))->formatStateUsing(fn ($state) => Usdt::format((int) $state)),
                BadgeColumn::make('status')->label(__('common.status'))
                    ->colors(['warning' => 'pending', 'success' => 'paid', 'gray' => ['expired', 'cancelled']])
                    ->formatStateUsing(fn (string $state) => __('usdt.dstatus_' . $state)),
                TextColumn::make('expires_at')->label(__('usdt.col_expires'))->formatStateUsing(fn ($state) => Jalali::dateTime($state)),
                TextColumn::make('transfer.tx_hash')->label(__('usdt.tx'))->placeholder('—')
                    ->formatStateUsing(fn ($state) => substr((string) $state, 0, 10) . '…')
                    ->url(fn (CryptoDeposit $record) => $record->transfer ? Usdt::txUrl($record->transfer->tx_hash) : null, shouldOpenInNewTab: true),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('common.status'))->options(collect(['pending', 'paid', 'expired', 'cancelled'])
                    ->mapWithKeys(fn ($s) => [$s => __('usdt.dstatus_' . $s)])->all()),
                SelectFilter::make('tenant_id')->relationship('tenant', 'name')->label(__('panel.tenant'))->searchable(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array {
        return ['index' => Pages\ListCryptoDeposits::route('/')];
    }
}
