<?php
namespace App\Filament\Customer\Widgets;

use App\Support\{Numbers, CustomerOnboarding, CustomerDashboardData};
use Filament\Widgets\Widget;

// The one dashboard widget that genuinely can't be served from
// analytics_daily — a daily rollup has no per-question text to group by.
// Scoped to this month, limited to 5; computed as part of the shared
// CustomerDashboardData cache (5 min) to bound the real cost of querying
// messages directly here.
class TopTopicsWidget extends Widget {
    protected static string $view = 'filament.customer.widgets.top-topics';
    protected int|string|array $columnSpan = 1;
    protected static bool $isLazy = false;

    public static function canView(): bool {
        $tenant = auth()->user()?->tenant;
        return $tenant && CustomerOnboarding::isComplete($tenant);
    }

    public function getRows(): array {
        $tenant = auth()->user()->tenant;
        return CustomerDashboardData::forTenant($tenant)['topTopics'];
    }

    public function fmt(int $n): string { return Numbers::format($n); }
}
