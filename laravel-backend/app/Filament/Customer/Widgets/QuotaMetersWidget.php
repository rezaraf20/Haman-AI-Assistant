<?php
namespace App\Filament\Customer\Widgets;

use App\Filament\Customer\Pages\BuyTokens;
use App\Support\{Jalali, Numbers, QuotaPeriod, Settings, CustomerOnboarding, CustomerDashboardData};
use Filament\Widgets\Widget;

// The plan's monthly quota and purchased tokens, deliberately as two
// separate meters and never one combined figure: they behave differently
// (one resets, the other never expires and is only drawn after the first
// runs out), and a single total would hide exactly that from the merchant.
class QuotaMetersWidget extends Widget {
    protected static string $view = 'filament.customer.widgets.quota-meters';
    protected int|string|array $columnSpan = 'full';
    protected static ?int $sort = 2;
    protected static bool $isLazy = false;

    public static function canView(): bool {
        $tenant = auth()->user()?->tenant;
        return $tenant && CustomerOnboarding::isComplete($tenant);
    }

    public function getMeters(): array {
        $tenant = auth()->user()->tenant;
        $limit = CustomerDashboardData::forTenant($tenant)['maxTokensMonthly'];
        $used = (int) $tenant->usage_tokens_current;
        $pct = $limit ? min(100, (int) round(($used / max($limit, 1)) * 100)) : null;

        // The same threshold QuotaService uses to e-mail the "approaching
        // your quota" warning, so the bar turns amber exactly when that
        // e-mail goes out rather than at a number of our own.
        $warnAt = (int) Settings::get('quota.warning_threshold_percent');
        $tone = match (true) {
            $pct === null => 'ai',
            $pct >= 100 => 'danger',
            $warnAt > 0 && $pct >= $warnAt => 'warning',
            default => 'ai',
        };

        return [
            'quota' => [
                'limit'   => $limit,
                'pct'     => $pct,
                'tone'    => $tone,
                'used'    => Numbers::format($used),
                'limit_f' => $limit ? Numbers::format($limit) : null,
                'days'    => Numbers::format(QuotaPeriod::daysUntilReset($tenant)),
                'date'    => Jalali::date(QuotaPeriod::nextReset($tenant)),
            ],
            'bonus' => [
                'amount'  => (int) $tenant->bonus_tokens,
                'amount_f'=> Numbers::format((int) $tenant->bonus_tokens),
                'buy_url' => BuyTokens::getUrl(),
            ],
        ];
    }
}
