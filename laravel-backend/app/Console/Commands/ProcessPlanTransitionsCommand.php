<?php
namespace App\Console\Commands;

use App\Mail\TrialNotice;
use App\Models\{Plan, Tenant, User};
use App\Support\{Jalali, MailSettings};
use Filament\Notifications\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Two plan transitions that must happen on a schedule rather than at the
 * moment a customer acts:
 *   - a trial past its 7 days moves to the free plan automatically — never
 *     suspended, never deleted, just downgraded (see Tenant::isAccessible()
 *     and every chatbot's data staying exactly where it is; only
 *     Chatbot::effectiveTools() changes, immediately, because it reads the
 *     plan live).
 *   - a scheduled downgrade (a customer picked a cheaper plan mid-cycle —
 *     see the "change plan" page) only actually applies once its own
 *     effective date arrives, never immediately.
 * 3-day and 1-day trial reminders fire once each, tracked on the tenant row
 * itself rather than a cache key — a cache flush must not cause a repeat
 * reminder, and (unlike a cache key) an admin can see exactly when each one
 * went out.
 */
class ProcessPlanTransitionsCommand extends Command {
    protected $signature   = 'plans:process-transitions';
    protected $description = 'Send trial-ending reminders, downgrade expired trials to free, and apply scheduled plan downgrades';

    public function handle(): void {
        $this->sendTrialReminders();
        $downgraded = $this->downgradeExpiredTrials();
        $applied = $this->applyScheduledDowngrades();
        $this->info("Downgraded {$downgraded} expired trial(s), applied {$applied} scheduled downgrade(s).");
    }

    private function trialPlanId(): ?string {
        return Plan::where('slug', 'trial')->value('id');
    }

    private function freePlanId(): ?string {
        return Plan::where('slug', 'free')->value('id');
    }

    private function sendTrialReminders(): void {
        $trialPlanId = $this->trialPlanId();
        if (!$trialPlanId) return;

        foreach ([3 => 'trial_reminder_3d_sent_at', 1 => 'trial_reminder_1d_sent_at'] as $days => $column) {
            $tenants = Tenant::where('plan_id', $trialPlanId)
                ->whereNotNull('trial_ends_at')
                ->whereNull($column)
                ->whereBetween('trial_ends_at', [now(), now()->addDays($days)])
                ->get();

            foreach ($tenants as $tenant) {
                $this->notifyTenant($tenant,
                    __('plan.trial_reminder_subject', ['days' => $days]),
                    __('plan.trial_reminder_body', ['days' => $days, 'date' => Jalali::date($tenant->trial_ends_at) ?? $tenant->trial_ends_at->toDateString()]),
                );
                $tenant->update([$column => now()]);
            }
        }
    }

    private function downgradeExpiredTrials(): int {
        $trialPlanId = $this->trialPlanId();
        $freePlanId = $this->freePlanId();
        if (!$trialPlanId || !$freePlanId) return 0;

        $expired = Tenant::where('plan_id', $trialPlanId)
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<', now())
            ->get();

        foreach ($expired as $tenant) {
            // Never suspended, never deleted — only the plan changes.
            // Chatbots, documents, conversations: all untouched. Every tool
            // request from here on reads Chatbot::effectiveTools() fresh,
            // so access narrows on the very next request, no separate
            // "disable tools" write needed and nothing to undo on a later
            // upgrade.
            $tenant->update(['plan_id' => $freePlanId, 'status' => 'active']);
            $this->notifyTenant($tenant, __('plan.trial_ended_subject'), __('plan.trial_ended_body'));
            $this->line("Downgraded trial to free: {$tenant->id} ({$tenant->email})");
        }

        return $expired->count();
    }

    private function applyScheduledDowngrades(): int {
        $due = Tenant::whereNotNull('pending_plan_id')
            ->whereNotNull('pending_plan_effective_at')
            ->where('pending_plan_effective_at', '<=', now())
            ->get();

        foreach ($due as $tenant) {
            $tenant->update([
                'plan_id' => $tenant->pending_plan_id,
                'pending_plan_id' => null,
                'pending_plan_effective_at' => null,
            ]);
            $this->line("Applied scheduled downgrade: {$tenant->id} ({$tenant->email}) -> plan {$tenant->plan_id}");
        }

        return $due->count();
    }

    /** In-app (always) + a real email when SMTP is configured — no SMS here; a trial notice is not urgent enough to justify the SMS cost/complexity this platform otherwise reserves for OTP and order-status codes. */
    private function notifyTenant(Tenant $tenant, string $subject, string $body): void {
        foreach (User::where('tenant_id', $tenant->id)->get() as $user) {
            Notification::make()->title($subject)->body($body)->warning()->sendToDatabase($user);
        }

        if (MailSettings::isUsable() && $tenant->email) {
            try {
                Mail::to($tenant->email)->send(new TrialNotice($subject, $body));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("ProcessPlanTransitionsCommand: email to {$tenant->email} failed — {$e->getMessage()}");
            }
        }
    }
}
