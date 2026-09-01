<?php
namespace App\Filament\Resources\ChatbotTypePriceResource\Pages;

use App\Filament\Resources\ChatbotTypePriceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListChatbotTypePrices extends ListRecords {
    protected static string $resource = ChatbotTypePriceResource::class;

    protected function getHeaderActions(): array {
        return [CreateAction::make()];
    }
}
