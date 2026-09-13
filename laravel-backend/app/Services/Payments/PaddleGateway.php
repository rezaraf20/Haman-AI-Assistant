<?php
namespace App\Services\Payments;

use App\Support\Settings;
use Illuminate\Support\Facades\Http;

/**
 * Paddle, via the Billing API's transaction flow.
 *
 * Same standing as StripeGateway: configurable now, working once a key is
 * entered, not yet exercised against a live account. Paddle differs from the
 * other two in one way worth knowing before the first real key goes in — it
 * is a merchant of record, so it settles tax itself and its reported totals
 * include it. The amount check in verifyPayment() therefore compares against
 * the transaction subtotal, not the grand total.
 */
class PaddleGateway implements PaymentGateway
{
    public function key(): string { return 'paddle'; }

    public function isConfigured(): bool
    {
        return Settings::isConfigured('paddle');
    }

    public function supportedCurrencies(): array { return ['USD', 'EUR', 'GBP']; }

    public function supportsCurrency(string $currency): bool
    {
        return in_array(strtoupper($currency), $this->supportedCurrencies(), true);
    }

    public function requestPayment(int $amount, string $currency, string $callbackUrl, string $description): array
    {
        if (!$this->supportsCurrency($currency)) {
            return ['ok' => false, 'message' => __('payments.currency_unsupported', ['gateway' => 'Paddle', 'currency' => $currency])];
        }
        if (!$this->isConfigured()) {
            return ['ok' => false, 'message' => __('payments.gateway_not_configured', ['gateway' => 'Paddle'])];
        }

        $response = Http::withToken($this->apiKey())
            ->timeout(20)
            ->post($this->apiBase() . '/transactions', [
                'items' => [[
                    'quantity' => 1,
                    'price' => [
                        'description'   => $description,
                        'unit_price'    => [
                            'amount'        => (string) ($amount * 100),
                            'currency_code' => strtoupper($currency),
                        ],
                    ],
                ]],
                'checkout' => ['url' => $callbackUrl],
                'custom_data' => ['description' => $description],
            ]);

        if (!$response->successful()) {
            return ['ok' => false, 'message' => $response->json('error.detail') ?? 'Paddle request failed'];
        }

        return [
            'ok'           => true,
            'reference'    => (string) $response->json('data.id'),
            'redirect_url' => (string) $response->json('data.checkout.url'),
        ];
    }

    public function verifyPayment(int $amount, string $currency, string $reference): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'message' => __('payments.gateway_not_configured', ['gateway' => 'Paddle'])];
        }

        $response = Http::withToken($this->apiKey())
            ->timeout(20)
            ->get($this->apiBase() . '/transactions/' . $reference);

        if (!$response->successful()) {
            return ['ok' => false, 'message' => $response->json('error.detail') ?? 'Paddle verify failed'];
        }

        if ($response->json('data.status') !== 'completed') {
            return ['ok' => false, 'message' => __('payments.not_paid')];
        }

        // Subtotal, not total: Paddle is the merchant of record and the
        // total it reports includes tax it collected on its own account.
        $subtotal = (int) $response->json('data.details.totals.subtotal');
        if ($subtotal !== $amount * 100) {
            return ['ok' => false, 'message' => __('payments.amount_mismatch')];
        }

        return ['ok' => true, 'ref_id' => $reference];
    }

    private function apiBase(): string
    {
        return Settings::get('payments.paddle.sandbox')
            ? 'https://sandbox-api.paddle.com'
            : 'https://api.paddle.com';
    }

    private function apiKey(): string
    {
        return (string) Settings::get('payments.paddle.api_key');
    }
}
