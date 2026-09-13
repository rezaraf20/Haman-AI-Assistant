<?php
namespace App\Filament\Widgets;

use App\Support\PlatformAccess;

use App\Models\Tenant;
use App\Support\{Money, Numbers};
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\{DB, Cache};

class PlatformStatsOverview extends StatsOverviewWidget {
    // Visible to staff; the three financial stats inside are stripped
    // for anyone without 'platform_finances' — see getStats().
    public static function canView(): bool { return PlatformAccess::allows('tenants_read'); }

    protected static ?string $pollingInterval = null;
    // Filament widgets default to $isLazy = true (loaded via a separate
    // Livewire round-trip after the initial page load) — this dashboard's
    // whole point is showing real numbers immediately.
    protected static bool $isLazy = false;

    protected function getStats(): array {
        $data = Cache::remember('dashboard:admin:stats-overview', 300, function () {
            // "vs. last month": tenants that already existed (and weren't
            // soft-deleted) at the start of this month, compared to the
            // current active count — an approximation (doesn't separately
            // track churn-during-month), not a historical snapshot table,
            // but a real signal from real created_at timestamps. Both counts
            // pulled in one query (Postgres FILTER) instead of two.
            $startOfMonth = now()->startOfMonth();
            $counts = Tenant::active()
                ->selectRaw("count(*) as active_now, count(*) filter (where created_at < ?) as active_last_month", [$startOfMonth])
                ->first();
            $activeNow = (int) $counts->active_now;
            $activeLastMonth = (int) $counts->active_last_month;

            $monthRow = DB::table('platform_daily_stats')
                ->where('date', '>=', $startOfMonth->toDateString())
                ->selectRaw('COALESCE(SUM(revenue_toman), 0) as revenue, COALESCE(SUM(cost_toman), 0) as cost')
                ->first();

            $revenue = (float) $monthRow->revenue;
            $cost    = (float) $monthRow->cost;
            $margin  = $revenue > 0 ? round((($revenue - $cost) / $revenue) * 100, 1) : null;

            return compact('activeNow', 'activeLastMonth', 'revenue', 'cost', 'margin');
        });

        $delta = $data['activeNow'] - $data['activeLastMonth'];
        $sign  = $delta > 0 ? '+' : ($delta < 0 ? '' : '±');

        $stats = [
            Stat::make(__('dashboard.admin_stats_active_tenants'), Numbers::format($data['activeNow']))
                ->description(__('dashboard.admin_stats_active_tenants_change', ['sign' => $sign, 'count' => Numbers::format(abs($delta))]))
                ->icon('heroicon-o-building-office-2')
                ->color($delta >= 0 ? 'success' : 'danger'),
        ];

        // How much the platform earns and spends is not support's business.
        // The tenant count above is, so the widget stays — it just stops
        // three stats short.
        if (!PlatformAccess::allows('platform_finances')) {
            return $stats;
        }

        return array_merge($stats, [
            Stat::make(__('dashboard.admin_stats_revenue_this_month'), Money::toman((int) $data['revenue']))
                ->icon('heroicon-o-banknotes')
                ->color('success'),

            Stat::make(__('dashboard.admin_stats_cost_this_month'), Money::toman((int) $data['cost']))
                ->icon('heroicon-o-cpu-chip')
                ->color('warning'),

            Stat::make(__('dashboard.admin_stats_gross_margin'), $data['margin'] === null ? '—' : Numbers::format($data['margin'], 1) . '%')
                ->icon('heroicon-o-chart-pie')
                ->color($data['margin'] === null ? 'gray' : ($data['margin'] >= 0 ? 'success' : 'danger')),
        ]);
    }
}
