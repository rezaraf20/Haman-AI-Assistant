<?php
namespace App\Filament\Resources\UsdtWalletTransactionResource\Pages;

use App\Filament\Resources\UsdtWalletTransactionResource;
use App\Models\Tenant;
use App\Services\WalletService;
use App\Support\{PlatformActivity, Usdt};
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateUsdtWalletTransaction extends CreateRecord {
    protected static string $resource = UsdtWalletTransactionResource::class;

    /** Through WalletService (balance and ledger together), and into the activity log with the admin's reason. */
    protected function handleRecordCreation(array $data): Model {
        $tenant = Tenant::findOrFail($data['tenant_id']);
        $raw = trim((string) $data['amount']);
        $micro = (int) Usdt::toMicro(ltrim($raw, '-')) * (str_starts_with($raw, '-') ? -1 : 1);

        // An admin may take a balance below zero (correcting a mistaken
        // credit already spent); the ledger records it either way.
        $txn = app(WalletService::class)->applyUsdt($tenant, $micro, 'admin_adjustment',
            ['description' => $data['description'], 'created_by' => auth()->id()], allowNegative: true);

        PlatformActivity::record('usdt_wallet_adjusted', tenantId: (string) $tenant->id,
            subjectType: 'usdt_wallet_transaction', subjectId: (string) $txn->id,
            after: ['amount' => Usdt::plain($micro), 'balance_after' => Usdt::plain((int) $txn->balance_after_micro), 'note' => (string) $data['description']]);

        return $txn;
    }
}
