<?php
namespace App\Filament\Customer\Pages;

use App\Support\{CustomerDashboardData, Jalali, QuotaPeriod};
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Contracts\Support\Htmlable;

// See App\Filament\Pages\Dashboard (admin) for why this override exists —
// same reasoning, customer-panel counterpart.
class Dashboard extends BaseDashboard {
    public static function getNavigationLabel(): string { return __('panel.dashboard_nav'); }
    public function getTitle(): string { return __('panel.dashboard_title'); }

    // The welcome line is the page's own header (Filament's heading +
    // subheading slots) rather than a widget, so it sits exactly where
    // every other portal page puts its title and one-line description.
    public function getHeading(): string {
        $user = auth()->user();
        $name = trim((string) ($user->first_name ?: $user->name));
        return $name !== ''
            ? __('portal.welcome_heading', ['name' => $name])
            : __('portal.welcome_heading_anonymous');
    }

    public function getSubheading(): string|Htmlable|null {
        return view('filament.customer.partials.welcome-meta', $this->welcomeMeta());
    }

    /** Store name, site, and plan status — all read from data the dashboard already loads. */
    private function welcomeMeta(): array {
        $tenant = auth()->user()->tenant;
        $data = CustomerDashboardData::forTenant($tenant);
        $plan = $data['plan'];

        $planName = $plan
            ? (app()->getLocale() === 'en' && filled($plan['name_en']) ? $plan['name_en'] : $plan['name'])
            : null;

        // Trial is the one plan with a real end date of its own (ProcessPlan
        // TransitionsCommand moves it to free when trial_ends_at passes).
        // Every other plan has no recurring charge in this codebase, so what
        // is shown is the end of the current quota period — the same cycle
        // ChangePlan prorates and schedules downgrades against — not a
        // "renewal" that nothing would actually perform.
        if ($plan && $plan['slug'] === 'trial' && $tenant->trial_ends_at) {
            $planTone = 'info';
            $planLine = $tenant->trial_ends_at->isPast()
                ? __('portal.welcome_trial_ended', ['date' => Jalali::date($tenant->trial_ends_at)])
                : __('portal.welcome_trial_ends', ['date' => Jalali::date($tenant->trial_ends_at)]);
        } else {
            $planTone = 'neutral';
            $planLine = $plan ? __('portal.welcome_period_ends', ['date' => Jalali::date(QuotaPeriod::anchoredPeriodEnd($tenant))]) : null;
        }

        return [
            'storeName' => $tenant->name,
            'site'      => $data['primaryDomain'],
            'planName'  => $planName,
            'planTone'  => $planTone,
            'planLine'  => $planLine,
        ];
    }

    public function getColumns(): int|array {
        return [
            'default' => 1,
            'lg'      => 3,
        ];
    }
}
