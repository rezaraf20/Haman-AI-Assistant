<?php
namespace App\Filament\Resources\PlanResource\Pages;

use App\Filament\Resources\PlanResource;
use App\Support\BrandDomains;
use Filament\Actions\{Action, CreateAction};
use Filament\Resources\Pages\ListRecords;

class ListPlans extends ListRecords {
    protected static string $resource = PlanResource::class;

    // These numbers aren't just admin bookkeeping — they're what a visitor
    // reads on the public site, so a change here is a change to the site,
    // not just to a database row.
    public function getSubheading(): ?string {
        return __('plans.list_subheading');
    }

    protected function getHeaderActions(): array {
        return [
            Action::make('view_on_site')
                ->label(__('plans.view_on_site'))
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->url(BrandDomains::landingUrl('/#pricing'))
                ->openUrlInNewTab(),
            CreateAction::make(),
        ];
    }
}
