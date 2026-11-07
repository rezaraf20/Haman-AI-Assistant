<?php
namespace App\Filament\Customer\Widgets;

use App\Support\{CustomerOnboarding, CustomerDashboardData};
use Filament\Widgets\Widget;

// Every new merchant hits the same wall: a bot that can't answer "where are
// you" or "what are your hours" because nobody ever filled those in. Shown
// above every other widget (see $sort) for as long as any chatbot is
// missing a basic profile field — unlike OnboardingChecklist, this doesn't
// disappear once first-setup is done; it tracks a separate, ongoing gap.
class BusinessProfileWarning extends Widget {
    protected static string $view = 'filament.customer.widgets.business-profile-warning';
    protected int|string|array $columnSpan = 'full';
    protected static ?int $sort = -9;
    protected static bool $isLazy = false;

    // Not while the onboarding checklist is up: that checklist has its own
    // business-profile step, and the same request twice on one screen is
    // noise. Once onboarding is done this takes over, unchanged.
    public static function canView(): bool {
        $tenant = auth()->user()?->tenant;
        return $tenant
            && CustomerOnboarding::isComplete($tenant)
            && CustomerDashboardData::forTenant($tenant)['profileGaps'] !== [];
    }

    public function getGaps(): array {
        return CustomerDashboardData::forTenant(auth()->user()->tenant)['profileGaps'];
    }
}
