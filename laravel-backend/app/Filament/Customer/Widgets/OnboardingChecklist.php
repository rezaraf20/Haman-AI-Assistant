<?php
namespace App\Filament\Customer\Widgets;

use App\Models\ApiKey;
use App\Support\CustomerOnboarding;
use Filament\Widgets\Widget;

// Only visible while onboarding is incomplete — see canView(). Every other
// customer dashboard widget does the opposite check (hides itself until
// onboarding is done), so a brand-new tenant sees exactly this checklist
// and nothing else: never an empty chart.
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
