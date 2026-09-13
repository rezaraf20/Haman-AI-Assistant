<?php
namespace App\Services\Payments;

use App\Support\Settings;
use Illuminate\Support\Facades\Http;

/**
 * Zarinpal, the gateway this platform actually runs on.
 *
 * The logic is carried over unchanged from ZarinpalService — including the
 * ×10 Toman-to-Rial conversion, which is the single most common integration
 * bug for this gateway and must not be "simplified" away. What changed is
 * only where it sits: behind PaymentGateway, so a second gateway does not
 * require rewriting the caller.
 */
class ZarinpalGateway implements PaymentGateway
{
    public function key(): string { return 'zarinpal'; }

    public function isConfigured(): bool
    {
        return Settings::isConfigured('zarinpal');
    }

    public function supportedCurrencies(): array { return ['IRT']; }

    public function supportsCurrency(string $currency): bool
    {
        return in_array(strtoupper($currency), $this->supportedCurrencies(), true);
    }

    public function requestPayment(int $amount, string $currency, string $callbackUrl, string $description): array
    {
        if (!$this->supportsCurrency($currency)) {
            return ['ok' => false, 'message' => __('payments.currency_unsupported', ['gateway' => 'Zarinpal', 'currency' => $currency])];
        }
        if (!$this->isConfigured()) {
            return ['ok' => false, 'message' => __('payments.gateway_not_configured', ['gateway' => 'Zarinpal'])];
        }

        $response = Http::timeout(20)->post("{$this->apiBase()}/request.json", [
            'merchant_id'  => $this->merchantId(),
            // Zarinpal's Amount is in Rial, not Toman. Do not remove this ×10.
            'amount'       => $amount * 10,
            'callback_url' => $callbackUrl,
            'description'  => $description,
        ]);

        $body = $response->json();
        if (($body['data']['code'] ?? null) !== 100) {
            return ['ok' => false, 'message' => $body['errors']['message'] ?? $body['data']['message'] ?? 'Zarinpal request failed'];
        }

        $authority = $body['data']['authority'];

        return [
            'ok'           => true,
            'reference'    => $authority,
            'redirect_url' => "{$this->startPayBase()}/{$authority}",
        ];
    }

    public function verifyPayment(int $amount, string $currency, string $reference): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'message' => __('payments.gateway_not_configured', ['gateway' => 'Zarinpal'])];
        }

        $response = Http::timeout(20)->post("{$this->apiBase()}/verify.json", [
            'merchant_id' => $this->merchantId(),
            'amount'      => $amount * 10,   // Rial, matching requestPayment.
            'authority'   => $reference,
        ]);

        $body = $response->json();
        $code = $body['data']['code'] ?? null;

        // 100 = verified now, 101 = already verified. The second is success:
        // idempotency at Zarinpal's end, on top of our own DB-level check.
        if ($code !== 100 && $code !== 101) {
            return ['ok' => false, 'message' => $body['errors']['message'] ?? $body['data']['message'] ?? 'Zarinpal verify failed'];
        }

        return ['ok' => true, 'ref_id' => (string) ($body['data']['ref_id'] ?? '')];
    }

    private function sandbox(): bool
    {
        return (bool) Settings::get('payments.zarinpal.sandbox');
    }

    private function merchantId(): string
    {
        return (string) Settings::get('payments.zarinpal.merchant_id');
    }

    private function apiBase(): string
    {
        return $this->sandbox()
            ? 'https://sandbox.zarinpal.com/pg/v4/payment'
            : 'https://api.zarinpal.com/pg/v4/payment';
    }

    private function startPayBase(): string
    {
        return $this->sandbox()
            ? 'https://sandbox.zarinpal.com/pg/StartPay'
            : 'https://www.zarinpal.com/pg/StartPay';
    }
}
