<?php
namespace App\Filament\Resources\WalletTransactionResource\Pages;

use App\Filament\Resources\WalletTransactionResource;
use App\Models\Tenant;
use App\Services\WalletService;
use App\Support\PlatformActivity;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateWalletTransaction extends CreateRecord {
    protected static string $resource = WalletTransactionResource::class;

    // Routes through WalletService so balance_after_toman and the tenant's
    // cached wallet_balance_toman stay correct — a plain Eloquent create()
    // from the form fields alone wouldn't touch either.
    protected function handleRecordCreation(array $data): Model {
        $tenant = Tenant::findOrFail($data['tenant_id']);
        $txn = app(WalletService::class)->applyCompletedTransaction(
            $tenant,
            'admin_adjustment',
            (int) $data['amount_toman'],
            ['description' => $data['description'], 'created_by' => auth()->id()],
        );

        // Handing out (or taking) money is the most reviewable thing an
        // admin does — in the activity log with everything else, not only
        // as a created_by on a ledger row.
        PlatformActivity::record('wallet_adjusted', tenantId: (string) $tenant->id,
            subjectType: 'wallet_transaction', subjectId: (string) $txn->id,
            // PlatformActivity's reason column takes only its fixed codes;
            // the admin's own words travel in the payload.
            after: ['amount_toman' => (int) $data['amount_toman'], 'balance_after_toman' => (int) $txn->balance_after_toman,
                'note' => (string) $data['description']]);

        return $txn;
    }
}
