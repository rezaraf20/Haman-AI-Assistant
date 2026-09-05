<?php
namespace App\Filament\Customer\Widgets;

use App\Support\{Money, Numbers, CustomerOnboarding, CustomerDashboardData};
use Filament\Widgets\Widget;

// Plain Widget (not StatsOverviewWidget) so the token-remaining card can
// render a real progress bar, which Stat::make() has no slot for — the
// customer explicitly asked for a bar, not just a number.
class CustomerStatsOverview extends Widget {
    protected static string $view = 'filament.customer.widgets.customer-stats-overview';
    protected int|string|array $columnSpan = 'full';
    // Filament widgets default to $isLazy = true (a separate Livewire
    // round-trip after the initial page load) — the dashboard's whole point
    // is showing real numbers immediately, not a placeholder that then pops
    // in a beat later.
    protected static bool $isLazy = false;

    public static function canView(): bool {
        $tenant = auth()->user()?->tenant;
        return $tenant && CustomerOnboarding::isComplete($tenant);
    }

    public function getData(): array {
        $tenant = auth()->user()->tenant;
        $data = CustomerDashboardData::forTenant($tenant);

        $limit = $data['maxTokensMonthly'];
        $used  = $tenant->usage_tokens_current;
        $pct   = $limit ? min(100, round(($used / max($limit, 1)) * 100)) : null;

        return [
            'questions_month' => (int) $data['monthQuestions'],
            'unanswered'      => (int) $data['monthUnanswered'],
            'wallet_toman'    => $tenant->wallet_balance_toman,
            'tokens_used'     => $used,
            'tokens_limit'    => $limit,
            'tokens_pct'      => $pct,
            'bonus_tokens'    => $tenant->bonus_tokens,
        ];
    }

    public function fmt(int $n): string { return Numbers::format($n); }
    public function toman(int $n): string { return Money::toman($n); }
}
