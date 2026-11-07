<?php
namespace App\Filament\Customer\Widgets;

use App\Support\{CustomerOnboarding, CustomerDashboardData};
use Filament\Widgets\ChartWidget;

// doc-04 "Intent analytics" (Very high) — the portal-visible half of
// intent_classifier.py's classification: how many questions fell into each
// intent over the last 30 days, ranked. One bar per intent in a single
// colour (the AI accent, since this is the model's classification, through
// Filament's own palette) rather than a stacked series per intent per day,
// which needed eleven distinct colours to tell apart. Only intents with a
// nonzero total get a bar, and an empty window shows a sentence instead of
// an empty axis.
class IntentsChart extends ChartWidget {
    protected static string $view = 'filament.customer.widgets.intents-chart';
    protected static ?string $pollingInterval = null;
    protected static string $color = 'primary';
    protected static ?string $maxHeight = '18rem';
    protected int|string|array $columnSpan = 2;
    protected static ?int $sort = 7;
    protected static bool $isLazy = false;

    public static function canView(): bool {
        $tenant = auth()->user()?->tenant;
        return $tenant && CustomerOnboarding::isComplete($tenant);
    }

    public function getHeading(): string {
        return __('portal.chart_intents_title');
    }

    public function getDescription(): ?string {
        return __('portal.chart_intents_desc');
    }

    public function hasAnyData(): bool {
        return $this->getData()['labels'] !== [];
    }

    protected function getData(): array {
        $totals = CustomerDashboardData::forTenant(auth()->user()->tenant)['intentTotals'];

        $labels = [];
        $values = [];
        foreach ($totals as $intent => $count) {
            if ($count <= 0) continue;
            $labels[] = __('dashboard.intent_' . $intent) !== 'dashboard.intent_' . $intent
                ? __('dashboard.intent_' . $intent) : $intent;
            $values[] = (int) $count;
        }

        return [
            'datasets' => [[
                'label' => __('portal.chart_intents_label'),
                'data'  => $values,
                'borderWidth' => 0,
                'borderRadius' => 4,
            ]],
            'labels' => $labels,
        ];
    }

    protected function getType(): string {
        return 'bar';
    }

    protected function getOptions(): array {
        $rtl = __('filament-panels::layout.direction') === 'rtl';

        return [
            'indexAxis' => 'y',
            'layout' => ['padding' => ['left' => 12, 'right' => 12]],
            'plugins' => [
                'legend' => ['display' => false],
                'tooltip' => ['rtl' => $rtl],
            ],
            'scales' => [
                'x' => ['reverse' => $rtl, 'beginAtZero' => true, 'ticks' => ['precision' => 0]],
                'y' => ['position' => $rtl ? 'right' : 'left'],
            ],
        ];
    }
}
