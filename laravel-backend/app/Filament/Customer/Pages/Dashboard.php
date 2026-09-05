<?php
namespace App\Filament\Customer\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

// See App\Filament\Pages\Dashboard (admin) for why this override exists —
// same reasoning, customer-panel counterpart.
class Dashboard extends BaseDashboard {
    public static function getNavigationLabel(): string { return __('panel.dashboard_nav'); }
    public function getTitle(): string { return __('panel.dashboard_title'); }

    public function getColumns(): int|array {
        return [
            'default' => 1,
            'lg'      => 3,
        ];
    }
}
