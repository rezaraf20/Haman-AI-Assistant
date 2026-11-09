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

        // Status is NOT trusted either way. A Status=NOK used to fail the
        // top-up outright — but the callback is public and the Authority is
        // in the customer's own address bar, so anyone holding it could
        // fail a payment that had in fact gone through. Zarinpal's verify
        // answer is the only thing that decides; NOK just changes the
        // message when verify says "not paid".
        $outcome = $this->resolvePending($txn);

        return match ($outcome) {
            'completed' => ['ok' => true, 'message' => 'Wallet topped up successfully'],
            'pending'   => ['ok' => false, 'pending' => true, 'message' => __('payments.verification_delayed')],
            default     => ['ok' => false, 'message' => $status === 'OK' ? 'Payment verification failed' : 'Payment was not completed'],
        };
    }

    /**
     * Asks the gateway that took a pending top-up whether it was paid, and
     * settles the row accordingly:
     *   'completed' — paid; wallet credited (idempotent, see WalletService)
     *   'failed'    — the gateway's definite answer is "not paid"
     *   'pending'   — no answer (timeout, connection error): left for
     *                 payments:reverify-pending, never failed on a guess
     */
    public function resolvePending(WalletTransaction $txn): string {
        // Verify against the gateway that actually took the payment, which
        // is stored on the transaction — not whichever one is configured
        // for this currency today.
        $gatewayKey = $txn->gateway ?: 'zarinpal';
        $gateway = $this->gateways->get($gatewayKey);
        if (!$gateway || !$txn->gateway_authority) {
            $this->wallet->failPendingTransaction($txn, $gateway ? 'No gateway reference' : "Unknown gateway [{$gatewayKey}]");
            return 'failed';
        }

        $result = $gateway->verifyPayment($txn->amount_toman, 'IRT', $txn->gateway_authority);
        if ($result['ok']) {
            $done = $this->wallet->completePendingTransaction($txn, ['gateway_ref_id' => $result['ref_id']]);
            return $done->status === 'completed' ? 'completed' : $done->status;
        }
        if ($result['retryable'] ?? false) {
            return 'pending';
        }

        $done = $this->wallet->failPendingTransaction($txn, $result['message'] ?? 'Verification failed');
        return $done->status === 'pending' ? 'pending' : $done->status;
    }
}
