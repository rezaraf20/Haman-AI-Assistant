<?php
namespace App\Filament\Customer\Widgets;

use App\Support\{Money, Numbers, QuotaPeriod, CustomerOnboarding, CustomerDashboardData};
use Filament\Widgets\Widget;

// Plain Widget (not StatsOverviewWidget) so each card can carry a trend
// line in words and an explicit "no data" state, which Stat::make() has no
// slot for.
//
// Where each number comes from (see CustomerDashboardData):
//  - conversations / unanswered: analytics_daily, month to date through
//    yesterday, compared with the same days of last month.
//  - quota used: tenants.usage_tokens_current against plans.max_tokens_
//    monthly. That counter keeps no history once it resets, so this card
//    has no previous-period comparison and says when it resets instead.
//  - wallet: tenants.wallet_balance_toman, with this month's completed
//    wallet_transactions as the movement line.
class CustomerStatsOverview extends Widget {
    protected static string $view = 'filament.customer.widgets.customer-stats-overview';
    protected int|string|array $columnSpan = 'full';
    protected static ?int $sort = 1;
    // Filament widgets default to $isLazy = true (a separate Livewire
    // round-trip after the initial page load) — the dashboard's whole point
    // is showing real numbers immediately, not a placeholder that then pops
    // in a beat later.
    protected static bool $isLazy = false;

    public static function canView(): bool {
        $tenant = auth()->user()?->tenant;
        return $tenant && CustomerOnboarding::isComplete($tenant);
    }

    public function getCards(): array {
        $tenant = auth()->user()->tenant;
        $data = CustomerDashboardData::forTenant($tenant);

        $limit = $data['maxTokensMonthly'];
        $used = (int) $tenant->usage_tokens_current;
        $pct = $limit ? (int) round(($used / max($limit, 1)) * 100) : null;
        $days = QuotaPeriod::daysUntilReset($tenant);

        $walletDelta = (int) $data['walletMonthDelta'];

        return [
            [
                'label' => __('portal.stat_conversations'),
                'icon'  => 'heroicon-o-chat-bubble-left-right',
                'ai'    => false,
                'value' => Numbers::format((int) $data['monthConversations']),
                'trend' => $this->trend((int) $data['monthConversations'], $data['prevConversations'], higherIsBetter: true),
                'foot'  => __('portal.stat_through_yesterday'),
            ],
            [
                'label' => __('portal.stat_quota_used'),
                'icon'  => 'heroicon-o-cpu-chip',
                'ai'    => true,
                'value' => $pct === null ? __('dashboard.customer_stats_tokens_unlimited') : __('portal.percent', ['value' => Numbers::format($pct)]),
                'trend' => null,
                'foot'  => $pct === null
                    ? __('portal.stat_quota_unlimited_foot')
                    : __('portal.stat_quota_foot', ['days' => Numbers::format($days)]),
            ],
            [
                'label' => __('portal.stat_unanswered'),
                'icon'  => 'heroicon-o-question-mark-circle',
                'ai'    => false,
                'value' => Numbers::format((int) $data['monthUnanswered']),
                'trend' => $this->trend((int) $data['monthUnanswered'], $data['prevUnanswered'], higherIsBetter: false),
                'foot'  => __('portal.stat_through_yesterday'),
            ],
            [
                'label' => __('dashboard.customer_stats_wallet_balance'),
                'icon'  => 'heroicon-o-wallet',
                'ai'    => false,
                'value' => Money::forCurrentTenant((int) $tenant->wallet_balance_toman),
                'trend' => null,
                'foot'  => $data['walletMonthCount'] > 0
                    ? __('portal.stat_wallet_month_change', [
                        'amount' => ($walletDelta < 0 ? '−' : '+') . Money::forCurrentTenant(abs($walletDelta)),
                    ])
                    : __('portal.stat_wallet_no_transactions'),
            ],
        ];
    }

    /**
     * Compared with the same days of last month. Null $previous means there
     * is no complete day this month yet (it's the 1st) — said in words, not
     * rendered as a 0% that would look like a real comparison.
     *
     * @return array{tone: string, icon: string, text: string}
     */
    private function trend(int $current, ?int $previous, bool $higherIsBetter): array {
        if ($previous === null) {
            return ['tone' => 'flat', 'icon' => 'heroicon-m-minus', 'text' => __('portal.trend_first_day')];
        }
        if ($current === $previous) {
            return ['tone' => 'flat', 'icon' => 'heroicon-m-minus', 'text' => __('portal.trend_same')];
        }

        $up = $current > $previous;
        $tone = $up === $higherIsBetter ? 'good' : 'bad';
        $icon = $up ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down';

        // No percentage against a zero baseline — it would be infinite.
        if ($previous === 0) {
            return ['tone' => $tone, 'icon' => $icon, 'text' => __('portal.trend_from_zero')];
        }

        $pct = (int) round(abs($current - $previous) / $previous * 100);
        return [
            'tone' => $tone,
            'icon' => $icon,
            'text' => __($up ? 'portal.trend_up' : 'portal.trend_down', ['pct' => Numbers::format($pct)]),
        ];
    }
}
