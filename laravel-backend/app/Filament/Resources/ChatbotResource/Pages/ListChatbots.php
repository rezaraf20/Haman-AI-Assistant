<?php
namespace App\Filament\Resources\ChatbotResource\Pages;

use App\Filament\Resources\ChatbotResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListChatbots extends ListRecords {
    protected static string $resource = ChatbotResource::class;

    protected function getHeaderActions(): array {
        return [
            CreateAction::make(),
        ];
    }
}
