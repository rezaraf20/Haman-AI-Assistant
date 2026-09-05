<?php
namespace App\Filament\Widgets;

use App\Support\Jalali;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\{DB, Cache};

class RevenueVsCostChart extends ChartWidget {
    protected static ?string $pollingInterval = null;
    protected int|string|array $columnSpan = 2;
    protected static bool $isLazy = false;

    public function getHeading(): string {
        return __('dashboard.admin_chart_revenue_vs_cost');
    }

    protected function getData(): array {
        $rows = Cache::remember('dashboard:admin:revenue-vs-cost', 300, function () {
            $start = now()->subMonths(11)->startOfMonth()->toDateString();
            return DB::table('platform_daily_stats')
                ->where('date', '>=', $start)
                ->selectRaw("to_char(date, 'YYYY-MM') as month, SUM(revenue_toman) as revenue, SUM(cost_toman) as cost")
                ->groupBy('month')
                ->get()
                ->keyBy('month');
        });

        $labels = [];
        $revenue = [];
        $cost = [];
        for ($i = 11; $i >= 0; $i--) {
            $month = now()->subMonths($i)->startOfMonth();
            $key = $month->format('Y-m');
            $labels[] = Jalali::date($month);
            $revenue[] = (float) ($rows[$key]->revenue ?? 0);
            $cost[] = (float) ($rows[$key]->cost ?? 0);
        }

        return [
            'datasets' => [
                [
                    'label' => __('dashboard.admin_chart_revenue_label'),
                    'data'  => $revenue,
                    'backgroundColor' => '#16A34A',
                ],
                [
                    'label' => __('dashboard.admin_chart_cost_label'),
                    'data'  => $cost,
                    'backgroundColor' => '#DC2626',
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string {
        return 'bar';
    }
}
