<?php
namespace App\Filament\Resources\LlmProviderProfileResource\Pages;

use App\Filament\Resources\LlmProviderProfileResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLlmProviderProfiles extends ListRecords {
    protected static string $resource = LlmProviderProfileResource::class;

    protected function getHeaderActions(): array {
        return [CreateAction::make()];
    }
}
