<?php
namespace App\Filament\Customer\Widgets;

use App\Filament\Customer\Pages\DemandGap;
use App\Support\{Jalali, CustomerOnboarding, CustomerDashboardData};
use Filament\Widgets\Widget;

class RecentUnansweredWidget extends Widget {
    protected static string $view = 'filament.customer.widgets.recent-unanswered';
    protected int|string|array $columnSpan = 2;
    protected static ?int $sort = 5;
    protected static bool $isLazy = false;

    public static function canView(): bool {
        $tenant = auth()->user()?->tenant;
        return $tenant && CustomerOnboarding::isComplete($tenant);
    }

    public function getRows(): array {
        $tenant = auth()->user()->tenant;
        return CustomerDashboardData::forTenant($tenant)['recentUnanswered'];
    }

    public function when($createdAt): ?string {
        return Jalali::dateTime($createdAt);
    }

    public function demandGapUrl(): string {
        return DemandGap::getUrl();
    }
}
