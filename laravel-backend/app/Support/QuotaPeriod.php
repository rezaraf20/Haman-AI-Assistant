<?php
namespace App\Support;

use App\Models\Tenant;
use Carbon\CarbonInterface;

/**
 * When a tenant's plan quota (usage_tokens_current) next goes back to zero,
 * for display only — this changes nothing about how or when it resets.
 *
 * Two independent mechanisms reset it today, and whichever fires first wins:
 *  - routes/console.php schedules haman:reset-usage on the 1st of every
 *    calendar month, which zeroes every tenant at once
 *    (TenantService::resetMonthlyUsage()).
 *  - QuotaService::rolloverPeriodIfDue() rolls each tenant's own period
 *    forward one month from quota_period_started_at (falling back to
 *    created_at), lazily, on the first request after that date.
 * Showing only one of them would sometimes promise a reset date that the
 * other one beats, so this reports the earlier of the two.
 */
class QuotaPeriod
{
    /** End of the tenant's own anchored period — the same cycle ChangePlan prorates against. */
    public static function anchoredPeriodEnd(Tenant $tenant): CarbonInterface
    {
        $anchor = ($tenant->quota_period_started_at ?? $tenant->created_at ?? now())->copy();
        $end = $anchor->copy()->addMonthsNoOverflow(max(0, (int) $anchor->diffInMonths(now())));
        while ($end->lessThanOrEqualTo(now())) {
            $end->addMonthNoOverflow();
        }
        return $end;
    }

    public static function nextReset(Tenant $tenant): CarbonInterface
    {
        $calendar = now()->startOfMonth()->addMonthNoOverflow();
        $anchored = self::anchoredPeriodEnd($tenant);
        return $anchored->lessThan($calendar) ? $anchored : $calendar;
    }

    /** Whole days until nextReset(), never negative; 0 means "later today". */
    public static function daysUntilReset(Tenant $tenant): int
    {
        return max(0, (int) floor(now()->diffInDays(self::nextReset($tenant), false)));
    }
}
