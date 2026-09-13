<?php
namespace App\Services\Payments;

use App\Support\Settings;
use Illuminate\Support\Facades\Http;

/**
 * Stripe, via Checkout Sessions.
 *
 * Written against Stripe's REST API directly rather than pulling in the SDK:
 * two endpoints are needed, the shapes are stable, and the CDN/dependency
 * cost of the SDK buys nothing here.
 *
 * Not yet exercised against a live account — there is no Stripe key on this
 * platform to test with. It is configurable now and will work when one is
 * entered, which is what was asked for. The request/verify shapes below are
 * Stripe's documented Checkout flow; the one thing a first real key should
 * confirm is the zero-decimal currency list in minorUnits().
 */
class StripeGateway implements PaymentGateway
{
    private const API = 'https://api.stripe.com/v1';

    /**
     * Currencies Stripe treats as having no minor unit. Sending 1000 for
     * ¥1000 is right; sending it for $10.00 would charge $1000.
     */
    private const ZERO_DECIMAL = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW',
                                  'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    public function key(): string { return 'stripe'; }

    public function isConfigured(): bool
    {
        return Settings::isConfigured('stripe');
    }

    public function supportedCurrencies(): array { return ['USD', 'EUR', 'GBP']; }

    public function supportsCurrency(string $currency): bool
    {
        return in_array(strtoupper($currency), $this->supportedCurrencies(), true);
    }

    public function requestPayment(int $amount, string $currency, string $callbackUrl, string $description): array
    {
        if (!$this->supportsCurrency($currency)) {
            return ['ok' => false, 'message' => __('payments.currency_unsupported', ['gateway' => 'Stripe', 'currency' => $currency])];
        }
        if (!$this->isConfigured()) {
            return ['ok' => false, 'message' => __('payments.gateway_not_configured', ['gateway' => 'Stripe'])];
        }

        $response = Http::withToken($this->secretKey())
            ->asForm()
            ->timeout(20)
            ->post(self::API . '/checkout/sessions', [
                'mode'        => 'payment',
                'success_url' => $callbackUrl . (str_contains($callbackUrl, '?') ? '&' : '?') . 'status=OK',
                'cancel_url'  => $callbackUrl . (str_contains($callbackUrl, '?') ? '&' : '?') . 'status=NOK',
                'line_items'  => [[
                    'quantity'   => 1,
                    'price_data' => [
                        'currency'     => strtolower($currency),
                        'unit_amount'  => $this->minorUnits($amount, $currency),
                        'product_data' => ['name' => $description],
                    ],
                ]],
            ]);

        if (!$response->successful()) {
            return ['ok' => false, 'message' => $response->json('error.message') ?? 'Stripe request failed'];
        }

        return [
            'ok'           => true,
            'reference'    => (string) $response->json('id'),
            'redirect_url' => (string) $response->json('url'),
        ];
    }

    public function verifyPayment(int $amount, string $currency, string $reference): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'message' => __('payments.gateway_not_configured', ['gateway' => 'Stripe'])];
        }

        $response = Http::withToken($this->secretKey())
            ->timeout(20)
            ->get(self::API . '/checkout/sessions/' . $reference);

        if (!$response->successful()) {
            return ['ok' => false, 'message' => $response->json('error.message') ?? 'Stripe verify failed'];
        }

        if ($response->json('payment_status') !== 'paid') {
            return ['ok' => false, 'message' => __('payments.not_paid')];
        }

        // The amount is re-checked against what we stored locally, because
        // a redirect that says "paid" proves nothing about how much.
        $expected = $this->minorUnits($amount, $currency);
        if ((int) $response->json('amount_total') !== $expected) {
            return ['ok' => false, 'message' => __('payments.amount_mismatch')];
        }

        return ['ok' => true, 'ref_id' => (string) ($response->json('payment_intent') ?: $reference)];
    }

    private function minorUnits(int $amount, string $currency): int
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL, true) ? $amount : $amount * 100;
    }

    private function secretKey(): string
    {
        return (string) Settings::get('payments.stripe.secret_key');
    }
}
