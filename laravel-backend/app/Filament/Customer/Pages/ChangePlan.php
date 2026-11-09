<?php
namespace App\Filament\Customer\Pages;

use App\Models\{Plan, Tenant};
use App\Exceptions\InsufficientBalance;
use App\Services\BillingService;
use App\Support\Money;
use Filament\Pages\Page;
use Filament\Tables\Table;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Upgrade or downgrade the tenant's own subscription plan — the one thing
 * the pricing page's "Get started" buttons never actually did (every
 * signup, on every path, hardcodes the free/trial plan; see
 * TenantService::register*()). This is deliberately the ONLY place
 * Tenant.plan_id ever changes outside an admin editing TenantResource
 * directly, or ProcessPlanTransitionsCommand applying a scheduled change.
 *
 * Upgrade: charged immediately, prorated by what is left of the current
 * period, paid until that period's end (tenants.plan_paid_until), after
 * which BillingService invoices and charges each month; takes effect
 * immediately — Chatbot::effectiveTools() and
 * QuotaService both read plan_id live, so nothing else has to change for
 * the new tools/limits to apply on the very next request.
 * usage_tokens_current is deliberately left untouched: the new plan's
 * quota takes over from whatever has already been used this period, it
 * does not restart at zero (see checkAndReserveTokens() reading the same
 * running counter against whichever plan is current).
 *
 * Downgrade: never immediate — scheduled via pending_plan_id/
 * pending_plan_effective_at for the end of the current quota period, and
 * applied by ProcessPlanTransitionsCommand. bonus_tokens (purchased,
 * never expiring) is never touched either way.
 */
class ChangePlan extends Page implements HasTable {
    use InteractsWithTable;

    protected static string $view = 'filament.customer.pages.change-plan';
    protected static ?string $navigationIcon = 'heroicon-o-arrow-trending-up';

    public static function getNavigationLabel(): string { return __('plan.change_plan_nav'); }
    public static function getNavigationGroup(): ?string { return __('panel.nav_group_customer_wallet'); }
    public function getTitle(): string { return __('plan.change_plan_title'); }
    public function getSubheading(): ?string { return __('portal.page_change_plan'); }

    public function getCurrentPlan(): Plan {
        return auth()->user()->tenant->plan;
    }

    public function getPendingPlan(): ?Plan {
        $tenant = auth()->user()->tenant;
        return $tenant->pending_plan_id ? Plan::find($tenant->pending_plan_id) : null;
    }

    public function table(Table $table): Table {
        return $table
            // Zebra rows and a sentence instead of Filament's generic
            // "no records" — see portal-theme.css for the header tint.
            ->striped()
            ->emptyStateIcon('heroicon-o-rectangle-stack')
            ->emptyStateHeading(__('portal.empty_plans_title'))
            ->emptyStateDescription(__('portal.empty_plans_desc'))
            ->query(fn () => Plan::where('is_public', true)->where('is_active', true)->orderBy('sort_order'))
            ->columns([
                TextColumn::make('display_name')->label(__('common.name')),
                TextColumn::make('price_monthly')->label(__('plans.price_monthly'))
                    ->formatStateUsing(fn (Plan $record) => Money::forCurrentTenant((int) $record->price_monthly)),
                TextColumn::make('max_tokens_monthly')->label(__('plans.max_tokens'))
                    ->formatStateUsing(fn (int $state) => \App\Support\Numbers::format($state)),
                TextColumn::make('max_chatbots')->label(__('plans.max_chatbots')),
            ])
            ->actions([
                Action::make('select')
                    ->label(fn (Plan $record) => $this->isUpgrade($record) ? __('plan.upgrade_action') : __('plan.downgrade_action'))
                    ->visible(fn (Plan $record) => $record->id !== $this->getCurrentPlan()->id)
                    ->color(fn (Plan $record) => $this->isUpgrade($record) ? 'success' : 'gray')
                    ->requiresConfirmation()
                    ->modalDescription(fn (Plan $record) => $this->isUpgrade($record)
                        ? __('plan.upgrade_confirm', ['price' => Money::forCurrentTenant($this->prorateDifference($record))])
                        : __('plan.downgrade_confirm', ['name' => $record->display_name]))
                    ->action(fn (Plan $record) => $this->isUpgrade($record) ? $this->upgrade($record) : $this->scheduleDowngrade($record)),
            ])
            ->defaultSort('sort_order');
    }

    private function isUpgrade(Plan $target): bool {
        return app(BillingService::class)->isUpgrade($this->getCurrentPlan(), $target);
    }

    /** What the upgrade will charge now — the same integer BillingService::upgradePlan() debits. */
    private function prorateDifference(Plan $target): int {
        $tenant = Tenant::findOrFail(auth()->user()->tenant_id);
        return app(BillingService::class)->prorate($tenant, Plan::findOrFail($tenant->plan_id), $target);
    }

    public function getPaidUntil(): ?\Carbon\CarbonInterface {
        return Tenant::find(auth()->user()->tenant_id)?->plan_paid_until;
    }

    private function upgrade(Plan $target): void {
        try {
            app(BillingService::class)->upgradePlan(Tenant::findOrFail(auth()->user()->tenant_id), $target);
        } catch (InsufficientBalance) {
            Notification::make()->title(__('wallet.insufficient_balance'))->body(__('wallet.topup_first'))->danger()->send();
            return;
        } catch (\DomainException) {
            Notification::make()->title(__('billing.unavailable'))->danger()->send();
            return;
        }

        Notification::make()->title(__('plan.upgrade_success', ['name' => $target->display_name]))->success()->send();
    }

    private function scheduleDowngrade(Plan $target): void {
        try {
            $effectiveAt = app(BillingService::class)->scheduleDowngrade(Tenant::findOrFail(auth()->user()->tenant_id), $target);
        } catch (\DomainException) {
            Notification::make()->title(__('billing.unavailable'))->danger()->send();
            return;
        }

        Notification::make()
            ->title(__('plan.downgrade_scheduled', ['name' => $target->display_name, 'date' => $effectiveAt->toDateString()]))
            ->success()->send();
    }
}
