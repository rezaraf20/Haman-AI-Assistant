<?php
namespace App\Filament\Customer\Widgets;

use App\Support\{Jalali, CustomerOnboarding, CustomerDashboardData};
use Filament\Widgets\ChartWidget;

class DailyConversationsChart extends ChartWidget {
    // Wraps Filament's own chart view with an empty state: a merchant whose
    // first nightly rollup hasn't run yet sees a sentence saying so, not a
    // flat line at zero that reads like "nobody used your bot".
    protected static string $view = 'filament.customer.widgets.daily-conversations-chart';
    protected static ?string $pollingInterval = null;
    // Filament's chart widget reads its line/fill colours from this palette
    // through CSS (see PortalTheme::filamentColors()), so no colour value
    // is written here at all. Primary's 500 shade is the AI accent.
    protected static string $color = 'primary';
    protected static ?string $maxHeight = '18rem';
    protected int|string|array $columnSpan = 2;
    protected static ?int $sort = 3;
    protected static bool $isLazy = false;

    public static function canView(): bool {
        $tenant = auth()->user()?->tenant;
        return $tenant && CustomerOnboarding::isComplete($tenant);
    }

    public function getHeading(): string {
        return __('dashboard.customer_chart_daily_conversations');
    }

    public function getDescription(): ?string {
        return __('portal.chart_daily_desc');
    }

    public function hasAnyData(): bool {
        return array_sum($this->getData()['datasets'][0]['data']) > 0;
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
                'fill'  => true,
                'tension' => 0.25,
                'pointRadius' => 2,
            ]],
            'labels' => $labels,
        ];
    }

    protected function getType(): string {
        return 'line';
    }

    protected function getOptions(): array {
        // Time runs in the reading direction: in RTL the oldest day sits on
        // the right and the newest on the left, with the value axis on the
        // side a reader starts from.
        $rtl = __('filament-panels::layout.direction') === 'rtl';

        return [
            'layout' => ['padding' => ['left' => 12, 'right' => 12]],
            'plugins' => [
                'legend' => ['display' => false],
                'tooltip' => ['rtl' => $rtl],
            ],
            'scales' => [
                'x' => ['reverse' => $rtl, 'ticks' => ['maxTicksLimit' => 4, 'maxRotation' => 0, 'autoSkipPadding' => 12]],
                'y' => ['position' => $rtl ? 'right' : 'left', 'beginAtZero' => true, 'ticks' => ['precision' => 0]],
            ],
        ];
    }
}
