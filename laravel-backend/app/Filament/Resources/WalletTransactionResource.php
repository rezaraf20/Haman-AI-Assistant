<?php
namespace App\Filament\Resources;

use App\Models\WalletTransaction;
use App\Models\Tenant;
use App\Services\WalletService;
use Filament\Forms\Form;
use Filament\Forms\Components\{TextInput, Select, Textarea};
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Tables\Columns\{TextColumn, BadgeColumn};
use Filament\Tables\Filters\SelectFilter;
use App\Support\Jalali;
use App\Filament\Resources\WalletTransactionResource\Pages;

class WalletTransactionResource extends Resource {
    protected static ?string $model = WalletTransaction::class;
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';
    protected static ?int $navigationSort = 5;

    public static function getNavigationLabel(): string { return __('wallet.transactions_nav'); }
    public static function getModelLabel(): string { return __('wallet.transaction_singular'); }
    public static function getPluralModelLabel(): string { return __('wallet.transaction_plural'); }

    // Only "create" is allowed here (for manual admin adjustments), via the
    // WalletService ledger helper — no Edit/Delete: transactions are an
    // append-only audit trail, not a table you correct in place.
    public static function canCreate(): bool { return true; }
    public static function canEdit($record): bool { return false; }
    public static function canDelete($record): bool { return false; }

    public static function form(Form $form): Form {
        return $form->schema([
            Select::make('tenant_id')
                ->label(__('panel.tenant'))
                ->options(fn () => Tenant::pluck('name', 'id'))
                ->searchable()
                ->required(),
            TextInput::make('amount_toman')
                ->label(__('wallet.amount_toman'))
                ->numeric()
                ->required()
                ->helperText(__('wallet.amount_toman_help')),
            Textarea::make('description')->label(__('common.description'))->required()->placeholder(__('wallet.adjustment_reason_placeholder')),
        ]);
    }

    public static function table(Table $table): Table {
        return $table
            ->columns([
                TextColumn::make('created_at')->label(__('wallet.date'))->formatStateUsing(fn ($state) => Jalali::dateTime($state))->sortable(),
                TextColumn::make('tenant.name')->label(__('panel.tenant'))->searchable(),
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
                TextColumn::make('amount_toman')->label(__('wallet.amount'))
                    ->formatStateUsing(fn (int $state) => number_format($state))
                    ->color(fn (int $state) => $state >= 0 ? 'success' : 'danger'),
                TextColumn::make('balance_after_toman')->label(__('wallet.balance_after'))
                    ->formatStateUsing(fn (int $state) => number_format($state)),
                BadgeColumn::make('status')->label(__('common.status'))->colors([
                    'success' => 'completed',
                    'warning' => 'pending',
                    'danger'  => ['failed', 'reversed'],
                ])->formatStateUsing(fn (string $state) => match ($state) {
                    'completed' => __('wallet.status_completed'), 'pending' => __('wallet.status_pending'),
                    'failed' => __('wallet.status_failed'), 'reversed' => __('wallet.status_reversed'),
                    default => $state,
                }),
                TextColumn::make('gateway')->label(__('wallet.gateway'))->placeholder('—'),
                TextColumn::make('gateway_ref_id')->label(__('wallet.gateway_ref_id'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('description')->label(__('common.description'))->limit(40),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('common.status'))->options([
                    'pending' => __('wallet.status_pending'), 'completed' => __('wallet.status_completed'),
                    'failed' => __('wallet.status_failed'), 'reversed' => __('wallet.status_reversed'),
                ]),
                SelectFilter::make('type')->label(__('wallet.type'))->options([
                    'topup' => __('wallet.type_topup'), 'plan_charge' => __('wallet.type_plan_charge'),
                    'admin_adjustment' => __('wallet.type_admin_adjustment'), 'refund' => __('wallet.type_refund'),
                ]),
                SelectFilter::make('tenant_id')->relationship('tenant', 'name')->label(__('panel.tenant'))->searchable(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array {
        return [
            'index'  => Pages\ListWalletTransactions::route('/'),
            'create' => Pages\CreateWalletTransaction::route('/create'),
        ];
    }
}
