<?php
namespace App\Services;

use App\Models\Tenant;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Filament\Notifications\Notification;

/**
 * The one place that answers "can this tenant do X right now" for every
 * quota/limit this platform enforces: chat tokens/messages, chatbot count,
 * domain count, document count. Every enforcement point (ChatService,
 * chatbot/domain creation, sync) must call this rather than compare against
 * plan limits inline, for the same reason Chatbot::effectiveTools() exists —
 * a check duplicated ad hoc at each call site is a check that is one missed
 * call site away from not existing at all.
 */
class QuotaService
{
    /**
     * Must be called BEFORE the LLM call, never after — checking after
     * always lets one over-budget response through, and at the scale of a
     * few thousand tenants that is real, uncapped cost, not a rounding
     * error.
     *
     * The real token count for a message is only known once the model has
     * actually answered, but the quota decision has to happen before that —
     * so this reserves a conservative estimate atomically (a row-locked
     * transaction: two concurrent requests against the last sliver of quota
     * cannot both read "allowed" and both reserve, because the second
     * request's lockForUpdate() blocks until the first's reservation has
     * already committed), and reconcileUsage() below corrects the estimate
     * down to the real figure once the response is in. The previous
     * implementation (Tenant::isTokenQuotaExceeded(), still used by
     * CheckTenantQuota's route-level check) read $tenant->usage_tokens_current
     * from an unlocked, possibly-stale in-memory value — exactly the race
     * this closes.
     *
     * @return array{allowed:bool, degrade:bool, bucket:string, reservation:int, reason:?string}
     *   bucket is 'plan'|'bonus'|'none' — which counter the reservation was
     *   taken from, so reconcileUsage() corrects the right one.
     */
    public function checkAndReserveTokens(Tenant $tenant): array
    {
        $estimate = max(0, (int) Settings::get('quota.token_reservation_estimate'));

        return DB::transaction(function () use ($tenant, $estimate) {
            $locked = Tenant::where('id', $tenant->id)->lockForUpdate()->firstOrFail();
            $this->rolloverPeriodIfDue($locked);
            $plan = $locked->plan;

            $messageLimit = $plan->max_messages_monthly ?? PHP_INT_MAX;
            if ($locked->usage_messages_current >= $messageLimit) {
                return ['allowed' => false, 'degrade' => false, 'bucket' => 'none', 'reservation' => 0, 'reason' => 'messages'];
            }

            $tokenLimit = $plan->max_tokens_monthly ?? PHP_INT_MAX;
            if ($locked->usage_tokens_current + $estimate <= $tokenLimit) {
                Tenant::where('id', $locked->id)->increment('usage_tokens_current', $estimate);
                return ['allowed' => true, 'degrade' => false, 'bucket' => 'plan', 'reservation' => $estimate, 'reason' => null];
            }

            // Plan's included quota is exhausted — behavior from here is a
            // plan setting (PlanResource), not a code decision.
            $behavior = $plan->quota_exceeded_behavior ?? 'stop';

            if ($behavior === 'auto_wallet' && $locked->bonus_tokens >= $estimate) {
                Tenant::where('id', $locked->id)->decrement('bonus_tokens', $estimate);
                return ['allowed' => true, 'degrade' => false, 'bucket' => 'bonus', 'reservation' => $estimate, 'reason' => null];
            }

            if ($behavior === 'degrade') {
                // Still tracked against the plan bucket (allowed to run
                // over — that's the point of degrading instead of
                // stopping), not dropped to 'none': the cheaper model still
                // spends real tokens, and reconcileUsage()'s bucket='none'
                // path records nothing at all. No reservation up front
                // here on purpose — a degraded response's cost is real but
                // deliberately not gated a second time by the same
                // estimate that already triggered this branch.
                return ['allowed' => true, 'degrade' => true, 'bucket' => 'plan', 'reservation' => 0, 'reason' => null];
            }

            return ['allowed' => false, 'degrade' => false, 'bucket' => 'none', 'reservation' => 0, 'reason' => 'tokens'];
        });
    }

    /**
     * Corrects a checkAndReserveTokens() reservation down (or up) to the
     * real token count once the response is in, in the same bucket the
     * reservation was taken from, then records the message. Also fires the
     * one-per-period usage warning at quota.warning_threshold_percent.
     */
    public function reconcileUsage(Tenant $tenant, string $bucket, int $reserved, int $actual): void
    {
        DB::transaction(function () use ($tenant, $bucket, $reserved, $actual) {
            $locked = Tenant::where('id', $tenant->id)->lockForUpdate()->firstOrFail();
            $delta = $actual - $reserved;

            if ($bucket === 'bonus') {
                if ($delta > 0) {
                    Tenant::where('id', $locked->id)->decrement('bonus_tokens', min($delta, max(0, $locked->bonus_tokens)));
                } elseif ($delta < 0) {
                    Tenant::where('id', $locked->id)->increment('bonus_tokens', -$delta);
                }
            } elseif ($bucket === 'plan' && $delta !== 0) {
                Tenant::where('id', $locked->id)->increment('usage_tokens_current', $delta);
            }

            Tenant::where('id', $locked->id)->increment('usage_messages_current');
            Tenant::where('id', $locked->id)->update(['last_active_at' => now()]);
        });

        $this->maybeWarnOnUsage(Tenant::find($tenant->id));
    }

    /**
     * Per-tenant-anchored reset: a tenant's quota period starts on their own
     * subscription date, not the calendar month, and rolls forward exactly
     * as many whole periods as have actually elapsed — if the scheduler
     * missed a day (or several), the next run catches up correctly instead
     * of only ever advancing by one period no matter how overdue. Must run
     * inside the SAME lockForUpdate() transaction as the usage check/
     * reservation above, so a request arriving right at the rollover
     * boundary sees a consistent read.
     */
    private function rolloverPeriodIfDue(Tenant $tenant): void
    {
        $anchor = $tenant->quota_period_started_at ?? $tenant->created_at ?? now();
        if ($anchor->greaterThan(now()->subMonthNoOverflow())) return; // less than one period old — nothing to do

        // How many whole months have elapsed since the anchor, advanced by
        // exactly that many — not jumped straight to "now" — so the next
        // period still starts on the tenant's own day-of-month.
        $periodsElapsed = $anchor->diffInMonths(now());
        $newAnchor = $anchor->copy()->addMonthsNoOverflow($periodsElapsed);

        Tenant::where('id', $tenant->id)->update([
            'quota_period_started_at' => $newAnchor,
            'usage_tokens_current'    => 0,
            'usage_messages_current'  => 0,
        ]);
        $tenant->quota_period_started_at = $newAnchor;
        $tenant->usage_tokens_current = 0;
        $tenant->usage_messages_current = 0;
    }

    /** Fires once per quota period, the first time usage crosses the configured threshold — never again until the next period rolls over. */
    private function maybeWarnOnUsage(Tenant $tenant): void
    {
        $threshold = (int) Settings::get('quota.warning_threshold_percent');
        if ($threshold <= 0) return;
        $limit = $tenant->plan->max_tokens_monthly ?? null;
        if (!$limit) return;

        $percent = ($tenant->usage_tokens_current / $limit) * 100;
        if ($percent < $threshold) return;

        $cacheKey = "quota-warned:{$tenant->id}:" . $tenant->quota_period_started_at?->toDateString();
        if (\Illuminate\Support\Facades\Cache::has($cacheKey)) return;
        \Illuminate\Support\Facades\Cache::put($cacheKey, true, now()->addDays(31));

        foreach ($tenant->users as $user) {
            Notification::make()
                ->title(__('plan.quota_warning_title'))
                ->body(__('plan.quota_warning_body', ['percent' => round($percent)]))
                ->warning()
                ->sendToDatabase($user);
        }
    }

    /** True when a tenant may create another chatbot under their current plan. */
    public function canCreateChatbot(Tenant $tenant, int $currentCount): bool
    {
        $limit = $tenant->plan->max_chatbots ?? PHP_INT_MAX;
        return $currentCount < $limit;
    }

    /** True when a tenant may add another domain to a chatbot under their current plan (counted across all of the tenant's chatbots, not per-chatbot). */
    public function canAddDomain(Tenant $tenant, int $currentCount): bool
    {
        $limit = $tenant->plan->max_domains ?? PHP_INT_MAX;
        return $currentCount < $limit;
    }

    /** True when a tenant may index another document under their current plan (counted per chatbot, matching max_documents' own per-chatbot framing on the pricing page). */
    public function canAddDocument(Tenant $tenant, int $currentCount): bool
    {
        $limit = $tenant->plan->max_documents ?? PHP_INT_MAX;
        return $currentCount < $limit;
    }
}
