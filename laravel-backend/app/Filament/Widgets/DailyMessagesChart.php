<?php
namespace App\Filament\Widgets;

use App\Support\Jalali;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\{DB, Cache};

class DailyMessagesChart extends ChartWidget {
    protected static ?string $pollingInterval = null;
    protected int|string|array $columnSpan = 2;
    protected static bool $isLazy = false;

    public function getHeading(): string {
        return __('dashboard.admin_chart_daily_messages');
    }

    protected function getData(): array {
        // Reads platform_daily_stats (written once a day by
        // AggregateAnalyticsJob) instead of aggregating raw messages across
        // every tenant schema on every dashboard load.
        $rows = Cache::remember('dashboard:admin:daily-messages', 300, function () {
            $start = now()->subDays(29)->toDateString();
            return DB::table('platform_daily_stats')
                ->where('date', '>=', $start)
                ->orderBy('date')
                ->get(['date', 'total_messages'])
                ->keyBy(fn ($r) => $r->date instanceof \DateTimeInterface ? $r->date->format('Y-m-d') : substr($r->date, 0, 10));
        });

        $labels = [];
        $values = [];
        for ($i = 29; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $key = $date->toDateString();
            $labels[] = Jalali::date($date);
            $values[] = (int) ($rows[$key]->total_messages ?? 0);
        }

        return [
            'datasets' => [[
                'label' => __('dashboard.admin_chart_daily_messages_label'),
                'data'  => $values,
                'borderColor' => config('hamman.brand.primary_color'),
                'backgroundColor' => 'rgba(27, 58, 107, 0.1)',
                'fill' => true,
            ]],
            'labels' => $labels,
        ];
    }

    protected function getType(): string {
        return 'line';
    }
}
