<?php
namespace App\Filament\Customer\Widgets;

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
}
