<?php
namespace App\Filament\Resources\WalletTransactionResource\Pages;

use App\Filament\Resources\WalletTransactionResource;
use App\Models\Tenant;
use App\Services\WalletService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateWalletTransaction extends CreateRecord {
    protected static string $resource = WalletTransactionResource::class;

    // Routes through WalletService so balance_after_toman and the tenant's
    // cached wallet_balance_toman stay correct — a plain Eloquent create()
    // from the form fields alone wouldn't touch either.
    protected function handleRecordCreation(array $data): Model {
        $tenant = Tenant::findOrFail($data['tenant_id']);
        return app(WalletService::class)->applyCompletedTransaction(
            $tenant,
            'admin_adjustment',
            (int) $data['amount_toman'],
            ['description' => $data['description'], 'created_by' => auth()->id()],
        );
    }
}
