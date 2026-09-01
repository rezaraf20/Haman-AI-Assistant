<?php
namespace App\Services;

use App\Models\Tenant;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

class WalletService {
    /**
     * Apply a completed transaction to a tenant's wallet — the only place
     * wallet_balance_toman is ever written. lockForUpdate() serializes
     * concurrent credits/debits against the same tenant (e.g. an admin
     * adjustment landing mid-Zarinpal-verify) so balance_after_toman and the
     * cached balance never race.
     */
    public function applyCompletedTransaction(
        Tenant $tenant, string $type, int $amountToman, array $attrs = []
    ): WalletTransaction {
        return DB::transaction(function () use ($tenant, $type, $amountToman, $attrs) {
            $locked = Tenant::where('id', $tenant->id)->lockForUpdate()->firstOrFail();
            $newBalance = $locked->wallet_balance_toman + $amountToman;

            $txn = WalletTransaction::create(array_merge([
                'tenant_id'           => $locked->id,
                'type'                => $type,
                'amount_toman'        => $amountToman,
                'balance_after_toman' => $newBalance,
                'status'              => 'completed',
            ], $attrs));

            $locked->update(['wallet_balance_toman' => $newBalance]);

            return $txn;
        });
    }

    /**
     * Two-phase counterpart to applyCompletedTransaction() for flows (like
     * Zarinpal) where a *pending* row must exist before redirecting the user
     * away, and only gets resolved to completed/failed later, out-of-band.
     */
    public function createPendingTransaction(Tenant $tenant, string $type, int $amountToman, array $attrs = []): WalletTransaction {
        return WalletTransaction::create(array_merge([
            'tenant_id'           => $tenant->id,
            'type'                => $type,
            'amount_toman'        => $amountToman,
            'balance_after_toman' => $tenant->wallet_balance_toman, // unknown until completed; placeholder
            'status'              => 'pending',
        ], $attrs));
    }

    /**
     * Resolves a pending transaction to completed, crediting/debiting the
     * wallet. Idempotent: returns the already-completed row unchanged if
     * called twice for the same transaction (guards against a refreshed/
     * replayed Zarinpal callback double-crediting the wallet).
     */
    public function completePendingTransaction(WalletTransaction $txn, array $attrs = []): WalletTransaction {
        return DB::transaction(function () use ($txn, $attrs) {
            $lockedTxn = WalletTransaction::where('id', $txn->id)->lockForUpdate()->firstOrFail();
            if ($lockedTxn->status !== 'pending') {
                return $lockedTxn; // already resolved — no-op, not an error.
            }
            $tenant = Tenant::where('id', $lockedTxn->tenant_id)->lockForUpdate()->firstOrFail();
            $newBalance = $tenant->wallet_balance_toman + $lockedTxn->amount_toman;

            $lockedTxn->update(array_merge([
                'status'              => 'completed',
                'balance_after_toman' => $newBalance,
            ], $attrs));
            $tenant->update(['wallet_balance_toman' => $newBalance]);

            return $lockedTxn;
        });
    }

    public function failPendingTransaction(WalletTransaction $txn, string $reason): WalletTransaction {
        $lockedTxn = WalletTransaction::where('id', $txn->id)->lockForUpdate()->firstOrFail();
        if ($lockedTxn->status === 'pending') {
            $lockedTxn->update(['status' => 'failed', 'description' => $reason]);
        }
        return $lockedTxn;
    }

    /** Reconciliation safety net: recompute the cached balance from the ledger. */
    public function reconcile(Tenant $tenant): array {
        $sum = WalletTransaction::where('tenant_id', $tenant->id)
            ->where('status', 'completed')
            ->sum('amount_toman');
        $drift = $sum - $tenant->wallet_balance_toman;
        if ($drift !== 0) {
            $tenant->update(['wallet_balance_toman' => $sum]);
        }
        return ['tenant_id' => $tenant->id, 'ledger_sum' => $sum, 'drift' => $drift];
    }
}
