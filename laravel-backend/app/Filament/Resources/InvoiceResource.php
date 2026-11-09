<?php
namespace App\Filament\Resources;

use App\Filament\Resources\InvoiceResource\Pages;
use App\Models\Invoice;
use App\Support\{Jalali, PlatformAccess, PlatformActivity};
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\{BadgeColumn, TextColumn};
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * Every tenant's renewal invoices (BillingService). Read-only: an invoice
 * is paid only by a wallet charge, never marked paid by hand. The one admin
 * action is voiding an open invoice, with a reason, recorded in the
 * activity log — voiding never touches the plan or takes money; it only
 * stops this invoice from being charged.
 */
class InvoiceResource extends Resource {
    public static function canViewAny(): bool { return PlatformAccess::allows('wallet_ledger'); }
    public static function canView($record): bool { return PlatformAccess::allows('wallet_ledger'); }
    public static function canCreate(): bool { return false; }
    public static function canEdit($record): bool { return false; }
    public static function canDelete($record): bool { return false; }
    public static function canDeleteAny(): bool { return false; }
    public static function shouldRegisterNavigation(): bool { return PlatformAccess::allows('wallet_ledger'); }

    protected static ?string $model = Invoice::class;
    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?int $navigationSort = 6;

    public static function getNavigationLabel(): string { return __('billing.admin_nav'); }
    public static function getNavigationGroup(): ?string { return __('panel.nav_group_finance'); }
    public static function getModelLabel(): string { return __('billing.invoice_singular'); }
    public static function getPluralModelLabel(): string { return __('billing.admin_nav'); }

    public static function table(Table $table): Table {
        return $table
            ->columns([
                TextColumn::make('number')->label(__('billing.col_number'))->sortable()->searchable(),
                TextColumn::make('tenant.name')->label(__('panel.tenant'))->searchable(),
                TextColumn::make('description')->label(__('billing.col_item'))
                    ->formatStateUsing(fn (Invoice $record) => __('billing.purpose_' . $record->purpose, ['name' => $record->description])),
                TextColumn::make('amount')->label(__('billing.col_amount'))
                    ->formatStateUsing(fn (Invoice $record) => \App\Support\Money::of((int) $record->amount, $record->currency)),
                TextColumn::make('due_at')->label(__('billing.col_due'))->formatStateUsing(fn ($state) => Jalali::dateTime($state))->sortable(),
                BadgeColumn::make('status')->label(__('common.status'))
                    ->colors(['warning' => 'open', 'success' => 'paid', 'gray' => 'void'])
                    ->formatStateUsing(fn (Invoice $record) => $record->status === 'void'
                        ? __('billing.status_void_' . ($record->void_reason ?: 'other'))
                        : __('billing.status_' . $record->status)),
                TextColumn::make('attempts')->label(__('billing.col_attempts')),
                TextColumn::make('last_failure')->label(__('billing.col_last_failure'))->placeholder('—')
                    ->formatStateUsing(fn ($state) => $state ? __('billing.failure_' . $state) : null),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('common.status'))->options([
                    'open' => __('billing.status_open'), 'paid' => __('billing.status_paid'), 'void' => __('billing.status_void_other'),
                ]),
                SelectFilter::make('tenant_id')->relationship('tenant', 'name')->label(__('panel.tenant'))->searchable(),
            ])
            ->actions([
                Action::make('void')
                    ->label(__('billing.void_action'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Invoice $record) => $record->status === 'open' && PlatformAccess::allows('wallet_ledger'))
                    ->form([Textarea::make('reason')->label(__('billing.void_reason'))->required()->maxLength(500)])
                    ->action(function (Invoice $record, array $data) {
                        abort_unless(PlatformAccess::allows('wallet_ledger'), 403);
                        $voided = DB::transaction(function () use ($record) {
                            $locked = Invoice::where('id', $record->id)->lockForUpdate()->first();
                            if (!$locked || $locked->status !== 'open') return false;
                            $locked->update(['status' => 'void', 'voided_at' => now(), 'void_reason' => 'admin']);
                            return true;
                        });
                        if ($voided) {
                            PlatformActivity::record('invoice_voided', tenantId: (string) $record->tenant_id,
                                subjectType: 'invoice', subjectId: (string) $record->id,
                                before: ['status' => 'open'],
                                after: ['status' => 'void', 'number' => $record->number, 'amount' => $record->amount, 'note' => (string) $data['reason']]);
                        }
                    }),
            ])
            ->defaultSort('issued_at', 'desc');
    }

    public static function getPages(): array {
        return ['index' => Pages\ListInvoices::route('/')];
    }
}
