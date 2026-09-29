<?php
namespace App\Console\Commands;

use App\Models\{PlatformSetting, Tenant, User};
use App\Models\Tenant\{Chatbot, Message};
use App\Support\{Money, Settings};
use Filament\Notifications\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB, Log};

/**
 * The automatic per-chatbot caps (ExpireOverdueChatbotsCommand,
 * EnforceTrialMessageLimitCommand) each bound the damage ONE trial chatbot
 * can do — this catches the case they don't: many trials, each individually
 * under its own cap, adding up to real money in one day, or one somehow
 * slipping past its cap between checks. Hourly, not daily, since the whole
 * point is catching a cost spike while it is still happening rather than
 * reporting on it the next morning.
 */
class CheckTrialCostCommand extends Command {
    protected $signature   = 'trial-chatbots:check-daily-cost';
    protected $description = 'Alert platform admins once per day if trial chatbots\' combined real cost exceeds the configured threshold';

    public function handle(): void {
        $threshold = (int) Settings::get('limits.trial_daily_cost_alert_toman');
        if ($threshold <= 0) return;

        $settings = PlatformSetting::current();
        if ($settings->trial_cost_alert_sent_at?->isToday()) return;

        $total = 0.0;
        try {
            foreach (Tenant::active()->get() as $tenant) {
                try {
                    DB::statement("SET search_path TO {$tenant->schema_name}, public");
                    $trialBotIds = Chatbot::where('type', 'trial')->pluck('id');
                    if ($trialBotIds->isEmpty()) continue;

                    $total += (float) Message::whereIn('chatbot_id', $trialBotIds)
                        ->whereDate('created_at', now()->toDateString())
                        ->sum('cost_toman');
                } catch (\Throwable $e) {
                    // Same posture as every other cross-tenant-schema loop
                    // in this app (FailedSyncsTable, AggregateAnalyticsJob):
                    // one broken tenant schema must not stop the check for
                    // every other tenant.
                    Log::warning("CheckTrialCostCommand: skipping tenant {$tenant->id} ({$tenant->schema_name}) — {$e->getMessage()}");
                }
            }
        } finally {
            DB::statement('SET search_path TO public');
        }

        if ($total < $threshold) {
            $this->info("Trial cost today: " . Money::toman((int) $total) . " — under the " . Money::toman($threshold) . " threshold.");
            return;
        }

        $admins = User::where('is_platform_admin', true)->get();
        foreach ($admins as $admin) {
            Notification::make()
                ->title(__('dashboard.trial_cost_alert_title'))
                ->body(__('dashboard.trial_cost_alert_body', [
                    'cost'      => Money::toman((int) $total),
                    'threshold' => Money::toman($threshold),
                ]))
                ->danger()
                ->sendToDatabase($admin);
        }

        // Once per calendar day, not once per hourly run — isToday() above
        // naturally allows a fresh alert once tomorrow's date arrives.
        $settings->update(['trial_cost_alert_sent_at' => now()]);
        $this->line("Alerted {$admins->count()} admin(s): trial cost today is " . Money::toman((int) $total));
    }
}
