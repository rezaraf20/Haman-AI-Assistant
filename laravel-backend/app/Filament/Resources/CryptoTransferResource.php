<?php
namespace App\Filament\Resources;

use App\Filament\Resources\CryptoTransferResource\Pages;
use App\Models\Crypto\CryptoTransfer;
use App\Models\Tenant;
use App\Services\Crypto\TransferReview;
use App\Support\{Jalali, PlatformAccess, Usdt};
use Filament\Forms\Components\{Select, Textarea};
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\{BadgeColumn, TextColumn};
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Every USDT transfer to the receiving address the scanner has read, with
 * its on-chain details. The "needs review" ones (no request matched them)
 * can be assigned to a tenant or ignored — with a reason, in the activity
 * log (TransferReview). Read-only otherwise.
 */
class CryptoTransferResource extends Resource {
    public static function canViewAny(): bool { return PlatformAccess::allows('wallet_ledger'); }
    public static function canView($record): bool { return PlatformAccess::allows('wallet_ledger'); }
    public static function canCreate(): bool { return false; }
    public static function canEdit($record): bool { return false; }
    public static function canDelete($record): bool { return false; }
    public static function canDeleteAny(): bool { return false; }
    public static function shouldRegisterNavigation(): bool { return PlatformAccess::allows('wallet_ledger'); }

    protected static ?string $model = CryptoTransfer::class;
    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';
    protected static ?int $navigationSort = 9;

    public static function getNavigationLabel(): string { return __('usdt.admin_transfers'); }
    public static function getNavigationGroup(): ?string { return __('panel.nav_group_finance'); }
    public static function getModelLabel(): string { return __('usdt.transfer_singular'); }
    public static function getPluralModelLabel(): string { return __('usdt.admin_transfers'); }

    private static ?int $unmatchedCount = null;

    /**
     * Payments waiting for review. No query at all while USDT is off, and
     * one per request otherwise — the navigation is rendered more than once
     * per page, and the admin dashboard has a query budget.
     */
    public static function getNavigationBadge(): ?string
    {
        if (!\App\Services\Crypto\UsdtDeposits::enabled()) return null;
        // Everything waiting for a person: no matching request, or not verifiable.
        self::$unmatchedCount ??= CryptoTransfer::whereIn('status', ['unmatched', 'stuck'])->count();
        return self::$unmatchedCount > 0 ? (string) self::$unmatchedCount : null;
    }

    public static function table(Table $table): Table {
        return $table
            ->columns([
                TextColumn::make('block_time')->label(__('usdt.col_time'))->formatStateUsing(fn ($state) => Jalali::dateTime($state))->sortable(),
                TextColumn::make('amount_base')->label(__('usdt.amount_col'))
                    ->formatStateUsing(fn (CryptoTransfer $record) => Usdt::isWholeMicro((string) $record->amount_base)
                        ? Usdt::format($record->amountMicro())
                        : Usdt::format($record->amountMicro()) . ' +'),
                BadgeColumn::make('status')->label(__('common.status'))
                    // reorged and stuck are warnings, not archive grey: either
                    // can be a customer's payment that was not credited.
                    ->colors(['info' => 'detected', 'warning' => ['unmatched', 'stuck'], 'danger' => 'reorged', 'success' => 'credited', 'gray' => 'ignored'])
                    ->formatStateUsing(fn (string $state) => __('usdt.tstatus_' . $state)),
                TextColumn::make('tenant.name')->label(__('panel.tenant'))->placeholder('—'),
                TextColumn::make('tx_hash')->label(__('usdt.tx'))
                    ->formatStateUsing(fn ($state) => substr($state, 0, 10) . '…' . substr($state, -6))
                    ->url(fn (CryptoTransfer $record) => Usdt::txUrl($record->tx_hash), shouldOpenInNewTab: true)
                    ->searchable(),
                TextColumn::make('from_address')->label(__('usdt.col_from'))
                    ->formatStateUsing(fn ($state) => substr($state, 0, 8) . '…' . substr($state, -6))->searchable(),
                TextColumn::make('block_number')->label(__('usdt.col_block'))->formatStateUsing(fn ($state) => number_format((int) $state)),
                TextColumn::make('note')->label(__('usdt.col_note'))->placeholder('—')->limit(40)
                    ->tooltip(fn (CryptoTransfer $record) => trim(($record->note ?? '') . ' ' . ($record->reorg_evidence ? '— ' . $record->reorg_evidence : '')) ?: null),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('common.status'))->options(collect(['unmatched', 'stuck', 'reorged', 'detected', 'credited', 'ignored'])
                    ->mapWithKeys(fn ($s) => [$s => __('usdt.tstatus_' . $s)])->all()),
            ])
            ->actions([
                // Stuck (never verifiable) or reorged: back to "detected", so
                // the scanner verifies it again from scratch. Credits nothing
                // by itself — only a verified, final transfer is credited.
                Action::make('recheck')
                    ->label(__('usdt.recheck_action'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (CryptoTransfer $record) => in_array($record->status, ['stuck', 'reorged'], true))
                    ->requiresConfirmation()
                    ->modalDescription(__('usdt.recheck_confirm'))
                    ->action(function (CryptoTransfer $record) {
                        abort_unless(PlatformAccess::allows('wallet_ledger'), 403);
                        app(\App\Services\Crypto\UsdtScanner::class)->recheck($record);
                        Notification::make()->title(__('usdt.rechecked'))->success()->send();
                    }),
                Action::make('assign')
                    ->label(__('usdt.assign_action'))
                    ->icon('heroicon-o-user-plus')
                    ->color('success')
                    ->visible(fn (CryptoTransfer $record) => $record->status === 'unmatched' && $record->finalized_at !== null)
                    ->form([
                        Select::make('tenant_id')->label(__('panel.tenant'))
                            ->options(fn () => Tenant::orderBy('name')->pluck('name', 'id'))->searchable()->required(),
                        Textarea::make('reason')->label(__('usdt.review_reason'))->required()->maxLength(500),
                    ])
                    ->modalDescription(fn (CryptoTransfer $record) => __('usdt.assign_confirm', ['amount' => Usdt::format($record->amountMicro())]))
                    ->action(function (CryptoTransfer $record, array $data) {
                        abort_unless(PlatformAccess::allows('wallet_ledger'), 403);
                        try {
                            app(TransferReview::class)->assign($record, Tenant::findOrFail($data['tenant_id']), (string) $data['reason']);
                            Notification::make()->title(__('usdt.assigned'))->success()->send();
                        } catch (\DomainException) {
                            Notification::make()->title(__('usdt.not_reviewable'))->warning()->send();
                        }
                    }),
                Action::make('ignore')
                    ->label(__('usdt.ignore_action'))
                    ->icon('heroicon-o-eye-slash')
                    ->color('gray')
                    ->visible(fn (CryptoTransfer $record) => $record->status === 'unmatched')
                    ->form([Textarea::make('reason')->label(__('usdt.review_reason'))->required()->maxLength(500)])
                    ->action(function (CryptoTransfer $record, array $data) {
                        abort_unless(PlatformAccess::allows('wallet_ledger'), 403);
                        try {
                            app(TransferReview::class)->ignore($record, (string) $data['reason']);
                        } catch (\DomainException) {
                            Notification::make()->title(__('usdt.not_reviewable'))->warning()->send();
                        }
                    }),
            ])
            ->defaultSort('block_number', 'desc');
    }

    public static function getPages(): array {
        return ['index' => Pages\ListCryptoTransfers::route('/')];
    }
}
