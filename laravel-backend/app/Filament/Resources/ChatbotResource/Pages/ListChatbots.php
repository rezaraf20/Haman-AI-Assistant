<?php
namespace App\Filament\Resources\ChatbotResource\Pages;

use App\Filament\Resources\ChatbotResource;
<<<<<<< HEAD
=======
use Filament\Actions\CreateAction;
>>>>>>> origin/develop
use Filament\Resources\Pages\ListRecords;

class ListChatbots extends ListRecords {
    protected static string $resource = ChatbotResource::class;
<<<<<<< HEAD
=======

    protected function getHeaderActions(): array {
        return [
            CreateAction::make(),
        ];
    }
>>>>>>> origin/develop
}
