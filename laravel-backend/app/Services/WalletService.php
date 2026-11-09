<?php
namespace App\Services;

use App\Models\Tenant;
use App\Models\{UsdtWalletTransaction, WalletTransaction};
use App\Exceptions\InsufficientBalance;
use Illuminate\Support\Facades\DB;

class WalletService {
    /**
     * The one way to spend from a wallet. Everything happens in a single
     * transaction, under the tenant row lock:
     *
     *   1. the balance is read AFTER the lock is taken — a page's earlier
     *      "can they afford it" check is advisory only, because two clicks
     *      (or a click and the renewal job) can both have passed it;
     *   2. the ledger row and the cached balance are written;
     *   3. $grant runs — the thing being bought (plan change, tokens, a new
     *      expiry date). If it throws, the charge rolls back with it, so a
     *      customer is never charged for something that was not granted.
     *
     * Before this, every purchase page locked the tenant outside any
     * transaction (a no-op in autocommit), checked the balance, then called
     * applyCompletedTransaction(), which never re-checked it — two parallel
     * purchases could both succeed against one balance.
     *
     * Two wallets, never mixed: IRT (whole Toman, wallet_transactions) and
     * USDT (micro-USDT, usdt_wallet_transactions). $currency picks which;
     * there is no conversion between them anywhere.
     *
     * @param int $amount positive, in the currency's minor unit; stored as a negative ledger amount
     * @param callable|null $grant fn(Tenant $lockedTenant, WalletTransaction|UsdtWalletTransaction $txn): void
     * @throws InsufficientBalance
     */
    public function debit(Tenant|string $tenant, int $amount, string $type, array $attrs = [], ?callable $grant = null, string $currency = 'IRT'): WalletTransaction|UsdtWalletTransaction {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('A debit amount must be positive.');
        }
        $currency = self::currency($currency);
        $tenantId = $tenant instanceof Tenant ? $tenant->id : $tenant;

        return DB::transaction(function () use ($tenantId, $amount, $type, $attrs, $grant, $currency) {
            $locked = Tenant::where('id', $tenantId)->lockForUpdate()->firstOrFail();
            $txn = $currency === 'USDT'
                ? $this->writeUsdt($locked, -$amount, $type, $attrs, allowNegative: false)
                : $this->writeToman($locked, -$amount, $type, $attrs);

            if ($grant) {
                $grant($locked, $txn);
            }

            return $txn;
        });
    }

    /** IRT ledger row + cached balance, on an already-locked tenant; refuses to go below zero. */
    private function writeToman(Tenant $locked, int $signed, string $type, array $attrs): WalletTransaction
    {
        $newBalance = (int) $locked->wallet_balance_toman + $signed;
        if ($signed < 0 && $newBalance < 0) {
            throw new InsufficientBalance((int) $locked->wallet_balance_toman, -$signed);
        }
        $txn = WalletTransaction::create(array_merge([
            'tenant_id'           => $locked->id,
            'type'                => $type,
            'amount_toman'        => $signed,
            'balance_after_toman' => $newBalance,
            'status'              => 'completed',
        ], $attrs));
        $locked->update(['wallet_balance_toman' => $newBalance]);
        return $txn;
    }

    /** USDT ledger row + cached balance, on an already-locked tenant. */
    private function writeUsdt(Tenant $locked, int $signedMicro, string $type, array $attrs, bool $allowNegative): UsdtWalletTransaction
    {
        $newBalance = (int) $locked->wallet_balance_usdt + $signedMicro;
        if ($signedMicro < 0 && $newBalance < 0 && !$allowNegative) {
            throw new InsufficientBalance((int) $locked->wallet_balance_usdt, -$signedMicro);
        }
        $txn = UsdtWalletTransaction::create(array_merge([
            'tenant_id'           => $locked->id,
            'type'                => $type,
            'amount_micro'        => $signedMicro,
            'balance_after_micro' => $newBalance,
        ], $attrs));
        $locked->update(['wallet_balance_usdt' => $newBalance]);
        return $txn;
    }

    /**
     * A credit (deposit, refund) or an admin adjustment to the USDT wallet.
     * A deposit passes crypto_transfer_id in $attrs — unique on the ledger,
     * so one on-chain transfer can never be credited twice.
     */
    public function applyUsdt(Tenant|string $tenant, int $signedMicro, string $type, array $attrs = [], bool $allowNegative = false): UsdtWalletTransaction
    {
        $tenantId = $tenant instanceof Tenant ? $tenant->id : $tenant;
        return DB::transaction(function () use ($tenantId, $signedMicro, $type, $attrs, $allowNegative) {
            $locked = Tenant::where('id', $tenantId)->lockForUpdate()->firstOrFail();
            return $this->writeUsdt($locked, $signedMicro, $type, $attrs, $allowNegative);
        });
    }

    public function balance(Tenant $tenant, string $currency): int
    {
        return self::currency($currency) === 'USDT' ? (int) $tenant->wallet_balance_usdt : (int) $tenant->wallet_balance_toman;
    }

    public static function currency(string $currency): string
    {
        $currency = strtoupper($currency);
        if (!in_array($currency, ['IRT', 'USDT'], true)) {
            throw new \InvalidArgumentException("Unknown wallet currency [{$currency}]");
        }
        return $currency;
    }

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

    /**
     * Inside a transaction: a lockForUpdate() in autocommit is released the
     * moment the SELECT finishes, so it could not stop a concurrent
     * completePendingTransaction() between the read and the update.
     */
    public function failPendingTransaction(WalletTransaction $txn, string $reason): WalletTransaction {
        return DB::transaction(function () use ($txn, $reason) {
            $lockedTxn = WalletTransaction::where('id', $txn->id)->lockForUpdate()->firstOrFail();
            if ($lockedTxn->status === 'pending') {
                $lockedTxn->update(['status' => 'failed', 'description' => $reason]);
            }
            return $lockedTxn;
        });
    }

    /** Reconciliation safety net: recompute the cached balance from the ledger. */
    /** Under the tenant lock, so a debit landing mid-reconcile cannot be overwritten by a stale sum. */
    public function reconcile(Tenant $tenant): array {
        return DB::transaction(function () use ($tenant) {
            $locked = Tenant::where('id', $tenant->id)->lockForUpdate()->firstOrFail();
            $sum = (int) WalletTransaction::where('tenant_id', $locked->id)
                ->where('status', 'completed')
                ->sum('amount_toman');
            $drift = $sum - (int) $locked->wallet_balance_toman;
            if ($drift !== 0) {
                $locked->update(['wallet_balance_toman' => $sum]);
            }
            $usdtSum = (int) UsdtWalletTransaction::where('tenant_id', $locked->id)->sum('amount_micro');
            $usdtDrift = $usdtSum - (int) $locked->wallet_balance_usdt;
            if ($usdtDrift !== 0) {
                $locked->update(['wallet_balance_usdt' => $usdtSum]);
            }
            return ['tenant_id' => $locked->id, 'ledger_sum' => $sum, 'drift' => $drift, 'usdt_drift' => $usdtDrift];
        });
    }
}
