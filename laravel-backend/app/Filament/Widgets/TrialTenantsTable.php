<?php
namespace App\Filament\Widgets;

use App\Support\PlatformAccess;

use App\Models\Tenant;
use App\Support\{Jalali, Money};
use Filament\Widgets\Widget;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\{DB, Cache, Log};

/**
 * Every signup now gets a live, working trial chatbot for free (see
 * TenantService::createTrialChatbot()) — real LLM cost with no payment
 * behind it. This is the admin-facing side of that: which trial chatbots
 * are currently active, what they have actually cost so far, and a manual
 * kill switch for one that's being abused, without waiting for the
 * automatic time/message-count caps to catch up.
 *
 * Same per-tenant-schema-loop shape as FailedSyncsTable, for the same
 * reason: chatbots.type and messages.cost_toman both live in each tenant's
 * own schema, there's no public-schema rollup to query instead.
 */
class TrialTenantsTable extends Widget {
    public static function canView(): bool { return PlatformAccess::allows('tenant_lifecycle'); }

    protected static string $view = 'filament.widgets.trial-tenants-table';
    protected int|string|array $columnSpan = 'full';
    // Unlike PlatformStatsOverview, nothing here needs to be on-screen the
    // instant the dashboard paints — Filament's default lazy loading (a
    // separate Livewire round-trip) keeps this per-tenant-schema loop off
    // the initial page load, so it stays cheap as the tenant count grows.

    private const CACHE_KEY = 'dashboard:admin:trial-tenants';

    public function getRows(): array {
        return Cache::remember(self::CACHE_KEY, 300, function () {
            $rows = [];
            try {
                foreach (Tenant::active()->get() as $tenant) {
                    try {
                        DB::statement("SET search_path TO {$tenant->schema_name}, public");
                        $bots = DB::table('chatbots')->where('type', 'trial')->get(['id', 'name']);

                        foreach ($bots as $bot) {
                            // chatbot_index.is_active, not the tenant-schema
                            // mirror: ExpireOverdueChatbotsCommand and
                            // EnforceTrialMessageLimitCommand only flip the
                            // public index when they suspend a trial, so the
                            // index is the one column guaranteed to be
                            // current — it's also what ValidateChatbotDomain
                            // itself gates real chat requests on.
                            $index = DB::table('chatbot_index')->where('chatbot_id', $bot->id)->first(['is_active']);
                            if (!$index || !$index->is_active) continue;

                            $usage = DB::table('messages')->where('chatbot_id', $bot->id)
                                ->selectRaw("COALESCE(SUM(cost_toman),0) AS cost, COALESCE(SUM(total_tokens),0) AS tokens, COUNT(*) FILTER (WHERE role = 'user') AS msg_count")
                                ->first();

                            $rows[] = [
                                'tenant_name'   => $tenant->name,
                                'chatbot_id'    => $bot->id,
                                'is_active'     => true,
                                'tokens'        => (int) ($usage->tokens ?? 0),
                                'cost_toman'    => (float) ($usage->cost ?? 0),
                                'message_count' => (int) ($usage->msg_count ?? 0),
                                'created_at'    => $tenant->created_at,
                            ];
                        }
                    } catch (\Throwable $e) {
                        // One tenant with an incomplete/broken schema must
                        // never take down this widget for every admin — see
                        // the identical guard in FailedSyncsTable.
                        Log::warning("TrialTenantsTable: skipping tenant {$tenant->id} ({$tenant->schema_name}) — {$e->getMessage()}");
                    }
                }
            } finally {
                DB::statement('SET search_path TO public');
            }

            // Only active ones were ever added above; the count of suspended
            // ones is implicit (EnforceTrialMessageLimitCommand /
            // ExpireOverdueChatbotsCommand already handled them). Costliest
            // first, since that's the one an admin most needs to see.
            usort($rows, fn ($a, $b) => $b['cost_toman'] <=> $a['cost_toman']);
            return $rows;
        });
    }

    /** Immediate, manual version of what the automatic caps do — for a trial being abused right now, not waiting for the hourly/daily check. */
    public function deactivate(string $chatbotId): void {
        PlatformAccess::authorize('tenant_lifecycle');

        if (!app(\App\Services\TenantService::class)->setChatbotActive($chatbotId, false, 'admin_suspended')) return;

        Cache::forget(self::CACHE_KEY);
        Notification::make()->title(__('dashboard.admin_table_trial_tenants_deactivated'))->success()->send();
    }

    public function formatMoney(float $toman): string { return Money::toman((int) $toman); }
    public function formatWhen($value): string { return Jalali::dateTime($value) ?? '—'; }
}
