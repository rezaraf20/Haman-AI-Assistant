<?php
namespace App\Filament\Customer\Widgets;

use App\Filament\Customer\Pages\{BuyChatbot, InstallGuide, MyChatbots, WidgetSettings};
use App\Models\ApiKey;
use App\Support\{Numbers, CustomerOnboarding, CustomerDashboardData};
use Filament\Widgets\Widget;

// Only visible while onboarding is incomplete — see canView(). Every other
// customer dashboard widget does the opposite check (hides itself until
// onboarding is done), so a brand-new tenant sees exactly this checklist
// and nothing else: never an empty chart.
//
// Five rows, each with a direct link to where that step is done. Four of
// them are CustomerOnboarding's own signals, and those four alone still
// decide when the real dashboard takes over (unchanged). The business
// profile row is shown here because it belongs to setting up, but it is
// not part of that gate: a merchant can be live with a thin profile, and
// BusinessProfileWarning keeps nagging about it on the real dashboard.
class OnboardingChecklist extends Widget {
    protected static string $view = 'filament.customer.widgets.onboarding-checklist';
    protected int|string|array $columnSpan = 'full';
    protected static ?int $sort = -10;
    // See CustomerStatsOverview — this is the very first thing a new tenant
    // sees, so it especially can't be left as a loading placeholder.
    protected static bool $isLazy = false;

    public static function canView(): bool {
        $tenant = auth()->user()?->tenant;
        return $tenant && !CustomerOnboarding::isComplete($tenant);
    }

    public function getStatus(): array {
        return CustomerOnboarding::status(auth()->user()->tenant);
    }

    /** @return list<array{key: string, done: bool, label: string, hint: string, cta: ?array{label: string, url: string, external: bool}}> */
    public function getItems(): array {
        $tenant = auth()->user()->tenant;
        $status = $this->getStatus();
        $data = $status['chatbot_created'] ? CustomerDashboardData::forTenant($tenant) : null;
        $firstChatbot = $data['chatbotStatuses'][0]['chatbot_id'] ?? null;
        $site = $data['primaryDomain'] ?? null;
        $profileDone = $status['chatbot_created'] && $firstChatbot !== null && $data['profileGaps'] === [];

        return [
            [
                'key' => 'chatbot',
                'done' => $status['chatbot_created'],
                'label' => __('dashboard.onboarding_chatbot_created'),
                'hint' => __('portal.onboarding_chatbot_hint'),
                'cta' => ['label' => __('dashboard.onboarding_chatbot_created_cta'), 'url' => BuyChatbot::getUrl(), 'external' => false],
            ],
            [
                'key' => 'profile',
                'done' => $profileDone,
                'label' => __('portal.onboarding_profile'),
                'hint' => $firstChatbot ? __('portal.onboarding_profile_hint') : __('portal.onboarding_needs_chatbot'),
                'cta' => $firstChatbot
                    ? ['label' => __('dashboard.profile_warning_cta'), 'url' => WidgetSettings::getUrl(['chatbot' => $firstChatbot]), 'external' => false]
                    : null,
            ],
            [
                'key' => 'plugin',
                'done' => $status['plugin_installed'],
                'label' => __('dashboard.onboarding_plugin_installed'),
                'hint' => __('portal.onboarding_plugin_hint'),
                'cta' => ['label' => __('portal.onboarding_plugin_cta'), 'url' => InstallGuide::getUrl(), 'external' => false],
            ],
            [
                'key' => 'sync',
                'done' => $status['first_sync_done'],
                'label' => __('dashboard.onboarding_first_sync'),
                'hint' => __('portal.onboarding_sync_hint'),
                'cta' => ['label' => __('portal.onboarding_sync_cta'), 'url' => MyChatbots::getUrl(), 'external' => false],
            ],
            [
                'key' => 'conversation',
                'done' => $status['first_conversation_done'],
                'label' => __('dashboard.onboarding_first_conversation'),
                'hint' => __('portal.onboarding_conversation_hint'),
                'cta' => $site
                    ? ['label' => __('portal.onboarding_conversation_cta'), 'url' => 'https://' . $site, 'external' => true]
                    : null,
            ],
        ];
    }

    public function progressLabel(array $items): string {
        $done = count(array_filter($items, fn ($i) => $i['done']));
        return __('portal.onboarding_progress', ['done' => Numbers::format($done), 'total' => Numbers::format(count($items))]);
    }

    public function progressPercent(array $items): int {
        return (int) round(count(array_filter($items, fn ($i) => $i['done'])) / max(1, count($items)) * 100);
    }

    /**
     * The "plugin installed" checklist row used to just sit there unchecked
     * forever with no way to act on it — no link, no key, nothing (see the
     * 2026-10-08 investigation: khonehrangi.ir's plugin never connected and
     * even a merchant who DID log in would have found nothing actionable
     * here). Only queried when actually needed (chatbot exists, plugin
     * hasn't connected yet) since every other checklist row needs nothing
     * past CustomerOnboarding::status()'s own cached query.
     *
     * @return array{chatbot_name: ?string, key: ?string}|null
     */
    public function getConnectionHelp(): ?array {
        $tenant = auth()->user()->tenant;
        $status = CustomerOnboarding::status($tenant);
        if (!$status['chatbot_created'] || $status['plugin_installed']) {
            return null;
        }

        $apiKey = ApiKey::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->whereNotNull('chatbot_id')
            ->with('chatbotIndexEntry')
            ->latest('created_at')
            ->first();

        if (!$apiKey) {
            return ['chatbot_name' => null, 'key' => null];
        }

        return [
            'chatbot_name' => $apiKey->chatbotIndexEntry?->name,
            'key' => $apiKey->revealKey(),
        ];
    }
}
