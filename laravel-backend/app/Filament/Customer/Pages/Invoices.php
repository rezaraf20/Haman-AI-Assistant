<?php
namespace App\Filament\Customer\Pages;

use App\Exceptions\{InsufficientBalance, InvoiceNotOpen};
use App\Models\Invoice;
use App\Services\BillingService;
use App\Support\{Jalali, Money, Numbers};
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\{BadgeColumn, TextColumn};
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * The tenant's renewal invoices (plan and paid chatbots). An open invoice is
 * charged from the wallet automatically on its due date; "Pay now" does the
 * same thing immediately — after a top-up, typically.
 */
class Invoices extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $view = 'filament.customer.pages.invoices';
    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    public static function getNavigationLabel(): string { return __('billing.nav'); }
    public static function getNavigationGroup(): ?string { return __('panel.nav_group_customer_wallet'); }
    public function getTitle(): string { return __('billing.nav'); }
    public function getSubheading(): ?string { return __('billing.subheading'); }

    public function table(Table $table): Table
    {
        return $table
            ->striped()
            ->emptyStateIcon('heroicon-o-document-text')
            ->emptyStateHeading(__('billing.empty_title'))
            ->emptyStateDescription(__('billing.empty_desc'))
            ->query(fn () => Invoice::query()->where('tenant_id', auth()->user()->tenant_id))
            ->columns([
                TextColumn::make('number')->label(__('billing.col_number'))->formatStateUsing(fn ($state) => Numbers::format((int) $state)),
                TextColumn::make('description')->label(__('billing.col_item'))
                    ->formatStateUsing(fn (Invoice $record) => __('billing.purpose_' . $record->purpose, ['name' => $record->description])),
                TextColumn::make('period_start')->label(__('billing.col_period'))
                    ->formatStateUsing(fn (Invoice $record) => (Jalali::date($record->period_start) ?? $record->period_start->toDateString())
                        . ' – ' . (Jalali::date($record->period_end) ?? $record->period_end->toDateString())),
                TextColumn::make('amount')->label(__('billing.col_amount'))->formatStateUsing(fn (int $state) => Money::forCurrentTenant($state)),
                TextColumn::make('due_at')->label(__('billing.col_due'))->formatStateUsing(fn ($state) => Jalali::date($state)),
                BadgeColumn::make('status')->label(__('common.status'))
                    ->colors(['warning' => 'open', 'success' => 'paid', 'gray' => 'void'])
                    ->formatStateUsing(fn (Invoice $record) => $record->status === 'void'
                        ? __('billing.status_void_' . ($record->void_reason ?: 'other'))
                        : __('billing.status_' . $record->status)),
            ])
            ->actions([
                Action::make('pay')
                    ->label(__('billing.pay_action'))
                    ->icon('heroicon-o-credit-card')
                    ->color('success')
                    ->visible(fn (Invoice $record) => $record->status === 'open')
                    ->requiresConfirmation()
                    ->modalDescription(fn (Invoice $record) => __('billing.pay_confirm', ['amount' => Money::forCurrentTenant((int) $record->amount)]))
                    ->action(fn (Invoice $record) => $this->pay($record)),
            ])
            ->defaultSort('issued_at', 'desc');
    }

    private function pay(Invoice $invoice): void
    {
        // The table query is already tenant-scoped; checked again because
        // this is the line that moves money.
        if ((string) $invoice->tenant_id !== (string) auth()->user()->tenant_id) return;

        try {
            app(BillingService::class)->payInvoice($invoice);
        } catch (InsufficientBalance) {
            Notification::make()->title(__('wallet.insufficient_balance'))->body(__('wallet.topup_first'))->danger()->send();
            return;
        } catch (InvoiceNotOpen) {
            Notification::make()->title(__('billing.invoice_not_open'))->warning()->send();
            return;
        }

        Notification::make()->title(__('billing.paid_success'))->success()->send();
    }
}
