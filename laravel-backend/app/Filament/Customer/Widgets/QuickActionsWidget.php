<?php
namespace App\Filament\Customer\Widgets;

use App\Filament\Customer\Pages\{ApiKeys, InstallGuide, Leads, MyChatbots, WidgetSettings};
use App\Support\{Numbers, CustomerOnboarding, CustomerDashboardData};
use Filament\Widgets\Widget;

// Shortcuts only — every action lands on the page that already owns it,
// so none of their logic (key reveal, the per-day manual sync cap, profile
// validation) is duplicated here.
class QuickActionsWidget extends Widget {
    protected static string $view = 'filament.customer.widgets.quick-actions';
    protected int|string|array $columnSpan = 1;
    protected static ?int $sort = 6;
    protected static bool $isLazy = false;

    public static function canView(): bool {
        $tenant = auth()->user()?->tenant;
        return $tenant && CustomerOnboarding::isComplete($tenant);
    }

    /** Leads collected in the last 7 days — the dashboard's one place for this count (see Leads.php on why the nav has no badge). */
    public function getNewLeads(): array {
        $count = (int) CustomerDashboardData::forTenant(auth()->user()->tenant)['newLeadsThisWeek'];
        return ['count' => $count, 'label' => Numbers::format($count), 'url' => Leads::getUrl()];
    }

    public function getActions(): array {
        $chatbots = CustomerDashboardData::forTenant(auth()->user()->tenant)['chatbotStatuses'];
        $first = $chatbots[0]['chatbot_id'] ?? null;

        return array_values(array_filter([
            ['label' => __('portal.quick_api_key'), 'icon' => 'heroicon-o-key', 'url' => ApiKeys::getUrl(), 'download' => false],
            ['label' => __('portal.quick_download_plugin'), 'icon' => 'heroicon-o-arrow-down-tray', 'url' => InstallGuide::pluginDownloadUrl(), 'download' => true],
            ['label' => __('portal.quick_sync'), 'icon' => 'heroicon-o-arrow-path', 'url' => MyChatbots::getUrl(), 'download' => false],
            $first ? ['label' => __('portal.quick_business_profile'), 'icon' => 'heroicon-o-building-storefront', 'url' => WidgetSettings::getUrl(['chatbot' => $first]), 'download' => false] : null,
        ]));
    }
}
