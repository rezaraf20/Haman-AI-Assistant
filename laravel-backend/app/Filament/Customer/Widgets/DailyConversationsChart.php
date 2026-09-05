<?php
namespace App\Filament\Customer\Widgets;

use App\Support\{Jalali, CustomerOnboarding};
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\{DB, Cache};

class DailyConversationsChart extends ChartWidget {
    protected static ?string $pollingInterval = null;
    protected int|string|array $columnSpan = 2;
    protected static bool $isLazy = false;

    public static function canView(): bool {
        $tenant = auth()->user()?->tenant;
        return $tenant && CustomerOnboarding::isComplete($tenant);
    }

    public function getHeading(): string {
        return __('dashboard.customer_chart_daily_conversations');
    }

    protected function getData(): array {
        $tenant = auth()->user()->tenant;

        $rows = Cache::remember("dashboard:customer:daily-conversations:{$tenant->id}", 300, function () use ($tenant) {
            DB::statement("SET search_path TO {$tenant->schema_name}, public");
            $data = DB::table('analytics_daily')
                ->where('date', '>=', now()->subDays(29)->toDateString())
                ->selectRaw('date, SUM(total_conversations) as convs')
                ->groupBy('date')
                ->get()
                ->keyBy(fn ($r) => $r->date instanceof \DateTimeInterface ? $r->date->format('Y-m-d') : substr($r->date, 0, 10));
            DB::statement('SET search_path TO public');
            return $data;
        });

        $labels = [];
        $values = [];
        for ($i = 29; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $key = $date->toDateString();
            $labels[] = Jalali::date($date);
            $values[] = (int) ($rows[$key]->convs ?? 0);
        }

        return [
            'datasets' => [[
                'label' => __('dashboard.customer_chart_conversations_label'),
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
