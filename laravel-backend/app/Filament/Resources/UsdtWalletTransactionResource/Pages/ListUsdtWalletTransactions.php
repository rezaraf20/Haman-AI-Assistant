<?php
namespace App\Filament\Resources\UsdtWalletTransactionResource\Pages;

use App\Filament\Resources\UsdtWalletTransactionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListUsdtWalletTransactions extends ListRecords {
    protected static string $resource = UsdtWalletTransactionResource::class;

    protected function getHeaderActions(): array {
        return [CreateAction::make()->label(__('wallet.manual_adjustment'))];
    }
}
