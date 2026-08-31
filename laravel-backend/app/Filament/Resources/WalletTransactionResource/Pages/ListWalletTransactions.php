<?php
namespace App\Filament\Resources\WalletTransactionResource\Pages;

use App\Filament\Resources\WalletTransactionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWalletTransactions extends ListRecords {
    protected static string $resource = WalletTransactionResource::class;

    protected function getHeaderActions(): array {
        return [CreateAction::make()->label('تنظیم دستی')];
    }
}
