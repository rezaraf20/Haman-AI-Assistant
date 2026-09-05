<?php
namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

// Overrides Filament's stock Dashboard purely to control the widget grid's
// responsive column count — stock default doesn't give 3-across-desktop/
// 1-across-mobile without this. Widgets themselves declare their own
// getColumnSpan() against this column count.
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
