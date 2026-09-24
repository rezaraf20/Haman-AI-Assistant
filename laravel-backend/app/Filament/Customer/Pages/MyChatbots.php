<?php
namespace App\Filament\Customer\Pages;

use App\Models\ChatbotIndexEntry;
use App\Models\Tenant\SyncJob;
use App\Services\WalletService;
use Filament\Pages\Page;
use Filament\Tables\Table;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Columns\{TextColumn, IconColumn};
use Filament\Tables\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use App\Support\Jalali;
use App\Support\Money;
use App\Support\Numbers;
use App\Support\Settings;
use App\Filament\Resources\TenantResource;
use Illuminate\Support\Facades\Cache;

class MyChatbots extends Page implements HasTable {
    use InteractsWithTable;

    protected static string $view = 'filament.customer.pages.my-chatbots';
    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    public static function getNavigationLabel(): string { return __('chatbot.my_chatbots_nav'); }
    public static function getNavigationGroup(): ?string { return __('panel.nav_group_customer_chatbots'); }
    public function getTitle(): string { return __('chatbot.my_chatbots_nav'); }

    public function table(Table $table): Table {
        return $table
            ->query(fn () => ChatbotIndexEntry::query()->where('tenant_id', auth()->user()->tenant_id))
            ->columns([
                TextColumn::make('name')->label(__('common.name'))->default(__('panel.chatbot_no_name')),
                TextColumn::make('primary_domain')->label(__('common.domain'))->placeholder('—'),
                IconColumn::make('is_active')->boolean()->label(__('common.active')),
                TextColumn::make('expires_at')->label(__('chatbot.expiry_date'))->formatStateUsing(fn ($state) => Jalali::date($state))
                    ->placeholder(__('common.unlimited'))
                    ->color(fn ($record) => $record->expires_at && $record->expires_at->isPast() ? 'danger' : null),
                TextColumn::make('monthly_price_toman')->label(__('chatbot.monthly_renewal_cost'))
                    ->formatStateUsing(fn (int $state) => $state > 0 ? Money::toman($state) : __('chatbot.contact_support')),
                TextColumn::make('sync_stats')
                    ->label(__('chatbot.sync_stats_label'))
                    ->getStateUsing(fn (ChatbotIndexEntry $record) => $this->syncStatsSummary($record))
                    ->placeholder(__('chatbot.sync_stats_none'))
                    ->wrap(),
            ])
            ->actions([
                // Superseded the old "appearance" modal (color/position/
                // powered-by only) — this is now the one place for every
                // content/appearance field (welcome message, chat title, AI
                // name, avatar, quick questions, system instruction), with
                // a live preview. See WidgetSettings.php's docblock for why
                // this moved server-side instead of staying split across
                // the WordPress plugin's own local options.
                Action::make('widget_settings')
                    ->label(__('chatbot.widget_settings_action'))
                    ->icon('heroicon-o-swatch')
                    ->color('gray')
                    ->url(fn (ChatbotIndexEntry $record) => WidgetSettings::getUrl(['chatbot' => $record->chatbot_id])),
                // What gets synced into this chatbot's index at all — see
                // SyncSettings.php's own docblock for the hamantech.ir
                // incident (a blog post's pricing table surfacing
                // mid-conversation) this exists to let a merchant prevent.
                Action::make('sync_settings')
                    ->label(__('chatbot.sync_settings_action'))
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->color('gray')
                    ->url(fn (ChatbotIndexEntry $record) => SyncSettings::getUrl(['chatbot' => $record->chatbot_id])),
                // The merchant's own copy of support's manual sync. Capped
                // per day because each run re-embeds the catalogue and that
                // costs real money; support is not capped here because the
                // plugin already allows one per site per hour and a
                // support-initiated sync is a deliberate act on a ticket.
                Action::make('manual_sync')
                    ->label(__('sync_trigger.action'))
                    ->icon('heroicon-o-arrow-path-rounded-square')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription(__('sync_trigger.confirm_customer'))
                    ->modalSubmitActionLabel(__('sync_trigger.action'))
                    ->action(fn (ChatbotIndexEntry $record) => $this->manualSync($record)),

                Action::make('renew')
                    ->label(__('chatbot.renew_action'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('success')
                    ->visible(fn (ChatbotIndexEntry $record) => $record->monthly_price_toman > 0)
                    ->requiresConfirmation()
                    ->modalDescription(fn (ChatbotIndexEntry $record) => __('chatbot.renew_confirm_description', ['amount' => Money::toman($record->monthly_price_toman)]))
                    ->action(fn (ChatbotIndexEntry $record) => $this->renew($record)),
            ]);
    }

    // Sums the most recent sync job of each content type (products, pages,
    // faqs) — "the results of the last time each was synced" — rather than
    // just the single latest job, since a full sync run creates one
    // SyncJob row per content type, not one combined row. Real-time
    // deletions (product.deleted/page.deleted webhooks) run through their
    // own 'deletion' job_type and are counted in separately so a deletion
    // between two full syncs still shows up here.
    private function syncStatsSummary(ChatbotIndexEntry $record): ?string {
        DB::statement("SET search_path TO {$record->schema_name}, public");
        $jobs = SyncJob::where('chatbot_id', $record->chatbot_id)
            ->whereIn('job_type', ['products', 'pages', 'faqs', 'deletion'])
            ->whereNotNull('completed_at')
            ->orderByDesc('completed_at')
            ->get()
            ->groupBy('job_type')
            ->map(fn ($jobs) => $jobs->first());
        DB::statement('SET search_path TO public');

        if ($jobs->isEmpty()) return null;

        $totals = ['new' => 0, 'updated' => 0, 'skipped' => 0, 'deleted' => 0];
        foreach ($jobs as $job) {
            foreach ($totals as $key => $_) {
                $totals[$key] += $job->result[$key] ?? 0;
            }
        }
        $lastSyncedAt = $jobs->max('completed_at');

        return __('chatbot.sync_stats_summary', [
            'new'     => Numbers::format($totals['new']),
            'updated' => Numbers::format($totals['updated']),
            'skipped' => Numbers::format($totals['skipped']),
            'deleted' => Numbers::format($totals['deleted']),
            'when'    => Jalali::dateTime($lastSyncedAt),
        ]);
    }

    /**
     * One counter per tenant per day, not per chatbot: the cost being
     * bounded is the tenant's embedding spend, and a tenant with four
     * chatbots should not get four times the budget.
     */
    private function manualSync(ChatbotIndexEntry $record): void {
        $tenant = auth()->user()->tenant;
        $cap = (int) Settings::get('limits.manual_sync_per_tenant_per_day');
        $key = "manual-sync:{$tenant->id}:" . now()->toDateString();

        if ($cap > 0 && (int) Cache::get($key, 0) >= $cap) {
            Notification::make()
                ->title(__('sync_trigger.failed_title'))
                ->body(__('sync_trigger.daily_cap_reached', ['cap' => $cap]))
                ->warning()->persistent()->send();
            return;
        }

        $ok = TenantResource::runManualSync($tenant, $record->chatbot_id);

        // Only a sync that actually ran counts against the budget — a
        // customer whose plugin is missing should not burn their allowance
        // discovering that three times.
        if ($ok && $cap > 0) {
            Cache::put($key, (int) Cache::get($key, 0) + 1,
                max(60, now()->endOfDay()->diffInSeconds(now())));
        }
    }

    private function renew(ChatbotIndexEntry $record): void {
        $tenant = auth()->user()->tenant;
        $price  = $record->monthly_price_toman;

        if ($tenant->wallet_balance_toman < $price) {
            Notification::make()
                ->title(__('wallet.insufficient_balance'))
                ->body(__('wallet.topup_first'))
                ->danger()
                ->send();
            return;
        }

        app(WalletService::class)->applyCompletedTransaction(
            $tenant,
            'plan_charge',
            -$price,
            ['description' => __('chatbot.renewal_description', ['name' => $record->name])],
        );

        $base = ($record->expires_at && $record->expires_at->isFuture()) ? $record->expires_at : now();
        $record->update([
            'expires_at' => $base->copy()->addMonth(),
            'is_active'  => true,
        ]);

        Notification::make()->title(__('chatbot.renewed_success'))->success()->send();
    }
}
