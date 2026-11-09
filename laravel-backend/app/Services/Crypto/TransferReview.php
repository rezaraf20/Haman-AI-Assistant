<?php
namespace App\Services\Crypto;

use App\Models\Crypto\CryptoTransfer;
use App\Models\Tenant;
use App\Services\WalletService;
use App\Support\{PlatformActivity, Usdt};
use Illuminate\Support\Facades\DB;

/**
 * The admin's side of USDT payments the scanner could not match on its own
 * (wrong amount, a request that expired long ago, no request at all).
 *
 * Only a transfer that is really on chain and final can be credited — the
 * admin chooses WHO, never WHETHER money arrived, and there is no way to
 * credit USDT without such a transfer. Every decision needs a reason and
 * lands in the activity log.
 */
class TransferReview
{
    public function __construct(private WalletService $wallet) {}

    /** @throws \DomainException not_reviewable */
    public function assign(CryptoTransfer $transfer, Tenant $tenant, string $reason): void
    {
        DB::transaction(function () use ($transfer, $tenant, $reason) {
            $locked = CryptoTransfer::where('id', $transfer->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'unmatched' || !$locked->finalized_at) {
                throw new \DomainException('not_reviewable');
            }
            $micro = $locked->amountMicro();
            $ledger = $this->wallet->applyUsdt($tenant, $micro, 'deposit', [
                'description' => __('usdt.deposit_description', ['tx' => substr($locked->tx_hash, 0, 12) . '…']),
                'metadata' => ['tx_hash' => $locked->tx_hash, 'block' => $locked->block_number, 'assigned_by_admin' => true],
                'crypto_transfer_id' => $locked->id,
                'created_by' => auth()->id(),
            ]);
            $locked->update(['status' => 'credited', 'credited_at' => now(), 'tenant_id' => $tenant->id,
                'usdt_wallet_transaction_id' => $ledger->id, 'note' => 'assigned by admin']);

            PlatformActivity::record('usdt_transfer_assigned', tenantId: (string) $tenant->id,
                subjectType: 'crypto_transfer', subjectId: (string) $locked->id,
                after: ['tx_hash' => $locked->tx_hash, 'amount' => Usdt::plain($micro), 'note' => $reason]);
        });
    }

    /** @throws \DomainException not_reviewable */
    public function ignore(CryptoTransfer $transfer, string $reason): void
    {
        DB::transaction(function () use ($transfer, $reason) {
            $locked = CryptoTransfer::where('id', $transfer->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'unmatched') {
                throw new \DomainException('not_reviewable');
            }
            $locked->update(['status' => 'ignored', 'note' => 'ignored by admin']);
            PlatformActivity::record('usdt_transfer_ignored', subjectType: 'crypto_transfer', subjectId: (string) $locked->id,
                after: ['tx_hash' => $locked->tx_hash, 'amount' => Usdt::plain($locked->amountMicro()), 'note' => $reason]);
        });
    }
}
