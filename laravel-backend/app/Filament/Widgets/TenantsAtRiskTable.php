<?php
namespace App\Filament\Widgets;

use App\Support\Money;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\{DB, Cache};

// Both signals (token quota usage, wallet balance) live on the public-schema
// Tenant/Plan rows — one joined query, no per-tenant schema switching
// needed, unlike FailedSyncsTable.
class TenantsAtRiskTable extends Widget {
    protected static string $view = 'filament.widgets.tenants-at-risk-table';
    protected int|string|array $columnSpan = 'full';
    protected static bool $isLazy = false;

    // A tenant nearing their plan's token ceiling is both a churn risk (if
    // they hit the wall and get frustrated) and a sales opportunity (ready
    // to upsell to a bigger plan) — same signal, two readings.
    private const QUOTA_RISK_THRESHOLD = 0.80;
    // No principled "low balance" figure exists elsewhere in the app yet;
    // flagged here as a starting point Reza can tune once real usage
    // patterns are visible on this exact widget.
    private const LOW_WALLET_TOMAN = 100000;

    public function getRows(): array {
        return Cache::remember('dashboard:admin:tenants-at-risk', 300, function () {
            // A single joined query instead of Tenant::active()->with('plan')
            // (2 queries) — this dashboard has a real query budget.
            $tenants = DB::table('tenants')
                ->leftJoin('plans', 'plans.id', '=', 'tenants.plan_id')
                ->whereIn('tenants.status', ['active', 'trial'])
                ->whereNull('tenants.deleted_at')
                ->select('tenants.name', 'tenants.usage_tokens_current', 'tenants.wallet_balance_toman', 'plans.max_tokens_monthly')
                ->get();
            $rows = [];

            foreach ($tenants as $tenant) {
                $limit = $tenant->max_tokens_monthly;
                if ($limit && $tenant->usage_tokens_current >= $limit * self::QUOTA_RISK_THRESHOLD) {
                    $rows[] = [
                        'tenant' => $tenant->name,
                        'reason' => __('dashboard.admin_table_tenants_at_risk_reason_quota'),
                        'value'  => round(($tenant->usage_tokens_current / $limit) * 100) . '%',
                    ];
                }
                if ($tenant->wallet_balance_toman < self::LOW_WALLET_TOMAN) {
                    $rows[] = [
                        'tenant' => $tenant->name,
                        'reason' => __('dashboard.admin_table_tenants_at_risk_reason_wallet'),
                        'value'  => Money::toman($tenant->wallet_balance_toman),
                    ];
                }
            }

            return $rows;
        });
    }
}
