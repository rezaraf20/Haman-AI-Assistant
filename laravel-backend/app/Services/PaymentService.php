<?php
namespace App\Services;

use App\Models\Tenant;
use App\Models\WalletTransaction;

class PaymentService {
    public function __construct(
        private ZarinpalService $zarinpal,
        private WalletService $wallet,
    ) {}

    /** @return array{ok:bool, redirect_url?:string, message?:string} */
    public function initTopup(Tenant $tenant, int $amountToman, string $callbackUrl): array {
        $txn = $this->wallet->createPendingTransaction($tenant, 'topup', $amountToman, [
            'gateway'     => 'zarinpal',
            'description' => "Wallet top-up: {$amountToman} Toman",
        ]);

        $result = $this->zarinpal->requestPayment($amountToman, $callbackUrl, "Hamman AI wallet top-up — {$tenant->name}");

        if (!$result['ok']) {
            $this->wallet->failPendingTransaction($txn, $result['message'] ?? 'Zarinpal request failed');
            return ['ok' => false, 'message' => $result['message'] ?? 'Payment initiation failed'];
        }

        $txn->update(['gateway_authority' => $result['authority']]);
        return ['ok' => true, 'redirect_url' => $result['redirect_url']];
    }

    /**
     * Handles the Zarinpal browser-redirect callback. Never trusts the
     * Authority/Status query params for anything beyond looking up which
     * local transaction this is about — the actual trust boundary is the
     * mandatory server-to-server verify() call below, using the amount we
     * stored locally at init time, not anything from the request.
     *
     * @return array{ok:bool, message:string}
     */
    public function handleCallback(string $authority, string $status): array {
        $txn = WalletTransaction::where('gateway_authority', $authority)->first();
        if (!$txn) {
            return ['ok' => false, 'message' => 'Unknown transaction'];
        }
        if ($txn->status !== 'pending') {
            // Already resolved (refreshed/replayed callback) — idempotent no-op.
            return ['ok' => $txn->status === 'completed', 'message' => 'Already processed'];
        }
        if ($status !== 'OK') {
            $this->wallet->failPendingTransaction($txn, 'User cancelled or gateway reported failure');
            return ['ok' => false, 'message' => 'Payment was not completed'];
        }

        $result = $this->zarinpal->verifyPayment($txn->amount_toman, $authority);
        if (!$result['ok']) {
            $this->wallet->failPendingTransaction($txn, $result['message'] ?? 'Verification failed');
            return ['ok' => false, 'message' => $result['message'] ?? 'Payment verification failed'];
        }

        $this->wallet->completePendingTransaction($txn, ['gateway_ref_id' => $result['ref_id']]);
        return ['ok' => true, 'message' => 'Wallet topped up successfully'];
    }
}
