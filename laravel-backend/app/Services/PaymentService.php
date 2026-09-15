<?php
namespace App\Services;

use App\Models\Tenant;
use App\Models\WalletTransaction;
use App\Services\Payments\PaymentGatewayManager;

class PaymentService {
    public function __construct(
        private PaymentGatewayManager $gateways,
        private WalletService $wallet,
    ) {}

    /**
     * @param string|null $currency Defaults to the platform's own currency.
     * @return array{ok:bool, redirect_url?:string, message?:string}
     */
    public function initTopup(Tenant $tenant, int $amountToman, string $callbackUrl, ?string $currency = null): array {
        $currency = strtoupper($currency ?? 'IRT');

        // Which processor takes this currency is a setting, so an
        // unconfigured route fails clearly here rather than at the API.
        $gateway = $this->gateways->forCurrency($currency);
        if (!$gateway) {
            return ['ok' => false, 'message' => __('payments.no_gateway_for_currency', ['currency' => $currency])];
        }
        if (!$gateway->isConfigured()) {
            return ['ok' => false, 'message' => __('payments.gateway_not_configured', ['gateway' => $gateway->key()])];
        }

        $txn = $this->wallet->createPendingTransaction($tenant, 'topup', $amountToman, [
            'gateway'     => $gateway->key(),
            'description' => "Wallet top-up: {$amountToman} Toman",
        ]);

        $result = $gateway->requestPayment($amountToman, $currency, $callbackUrl, "Haman AI wallet top-up — {$tenant->name}");

        if (!$result['ok']) {
            $this->wallet->failPendingTransaction($txn, $result['message'] ?? 'Payment request failed');
            return ['ok' => false, 'message' => $result['message'] ?? 'Payment initiation failed'];
        }

        $txn->update(['gateway_authority' => $result['reference']]);
        return ['ok' => true, 'redirect_url' => $result['redirect_url']];
    }

    /**
     * Handles the gateway's browser-redirect callback. Never trusts the
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

        // Verify against the gateway that actually took the payment, which
        // is stored on the transaction — not whichever one is configured
        // for this currency today.
        $gatewayKey = $txn->gateway ?: 'zarinpal';
        $gateway = $this->gateways->get($gatewayKey);
        if (!$gateway) {
            $this->wallet->failPendingTransaction($txn, "Unknown gateway [{$gatewayKey}]");
            return ['ok' => false, 'message' => 'Payment verification failed'];
        }

        $result = $gateway->verifyPayment($txn->amount_toman, 'IRT', $authority);
        if (!$result['ok']) {
            $this->wallet->failPendingTransaction($txn, $result['message'] ?? 'Verification failed');
            return ['ok' => false, 'message' => $result['message'] ?? 'Payment verification failed'];
        }

        $this->wallet->completePendingTransaction($txn, ['gateway_ref_id' => $result['ref_id']]);
        return ['ok' => true, 'message' => 'Wallet topped up successfully'];
    }
}
