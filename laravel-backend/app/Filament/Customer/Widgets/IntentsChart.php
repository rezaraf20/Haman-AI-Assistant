<?php
namespace App\Filament\Customer\Widgets;

use App\Support\{Jalali, CustomerOnboarding, CustomerDashboardData};
use Filament\Widgets\ChartWidget;

// doc-04 "Intent analytics" (Very high) — the portal-visible half of
// intent_classifier.py's classification: a stacked bar per day, one
// series per intent, over the same 30-day window every other customer
// dashboard chart uses. Only intents with a nonzero total in the window
// get their own series — a chatbot that's never seen a "returns"
// question shouldn't clutter the legend with an always-zero line.
class IntentsChart extends ChartWidget {
    protected static ?string $pollingInterval = null;
    protected int|string|array $columnSpan = 2;
    protected static bool $isLazy = false;

    // Stable per-intent colors so the same category is always the same
    // color across page loads — deliberately not auto-generated/random.
    private const COLORS = [
        'price'              => '#1B3A6B',
        'availability'       => '#0EA5E9',
        'comparison'         => '#8B5CF6',
        'consultation'       => '#14B8A6',
        'authenticity'       => '#EF4444',
        'shipping'           => '#F59E0B',
        'return'             => '#F97316',
        'payment'            => '#10B981',
        'order_status'       => '#6366F1',
        'technical_support'  => '#EC4899',
        'other'              => '#94A3B8',
    ];

    public static function canView(): bool {
        $tenant = auth()->user()?->tenant;
        return $tenant && CustomerOnboarding::isComplete($tenant);
    }

    public function getHeading(): string {
        return __('dashboard.customer_chart_intents');
    }

    protected function getData(): array {
        $tenant = auth()->user()->tenant;
        $data = CustomerDashboardData::forTenant($tenant);
        $dailyRows = $data['intentDailyRows'];
        $totals = $data['intentTotals'];

        $labels = [];
        $dateKeys = [];
        for ($i = 29; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $dateKeys[] = $date->toDateString();
            $labels[] = Jalali::date($date);
        }

        $datasets = [];
        foreach (array_keys($totals) as $intent) {
            if (($totals[$intent] ?? 0) <= 0) continue;
            $datasets[] = [
                'label' => __('dashboard.intent_' . $intent) !== 'dashboard.intent_' . $intent
                    ? __('dashboard.intent_' . $intent) : $intent,
                'data' => array_map(fn ($key) => (int) ($dailyRows[$key][$intent] ?? 0), $dateKeys),
                'backgroundColor' => self::COLORS[$intent] ?? '#CBD5E1',
            ];
        }

        return [
            'datasets' => $datasets,
            'labels'   => $labels,
        ];
    }

    protected function getType(): string {
        return 'bar';
    }

    protected function getOptions(): array {
        return [
            'scales' => [
                'x' => ['stacked' => true],
                'y' => ['stacked' => true, 'beginAtZero' => true, 'ticks' => ['precision' => 0]],
            ],
        ];
    }
}
