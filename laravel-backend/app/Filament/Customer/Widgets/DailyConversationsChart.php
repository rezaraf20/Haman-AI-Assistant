<?php
namespace App\Filament\Customer\Widgets;

use App\Support\{Jalali, CustomerOnboarding, CustomerDashboardData};
use Filament\Widgets\ChartWidget;

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
        $rows = CustomerDashboardData::forTenant($tenant)['dailyRows'];

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
