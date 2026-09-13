<?php
namespace App\Services\Payments;

use App\Support\Settings;

/**
 * Picks the gateway for a currency, and hands back gateways by name.
 *
 * The routing is a setting, not a rule in code, because which gateway takes
 * which currency is a commercial decision the platform owner makes — and
 * changes — without a deploy.
 *
 * forCurrency() deliberately does NOT silently fall back to another gateway
 * when the configured one has no keys. A top-up quietly going through a
 * different processor than the one the owner chose is worse than a clear
 * failure, so an unconfigured route says so.
 */
class PaymentGatewayManager
{
    /** @var array<string, PaymentGateway> */
    private array $gateways;

    public function __construct(ZarinpalGateway $zarinpal, StripeGateway $stripe, PaddleGateway $paddle)
    {
        $this->gateways = [
            $zarinpal->key() => $zarinpal,
            $stripe->key()   => $stripe,
            $paddle->key()   => $paddle,
        ];
    }

    /** @return array<string, PaymentGateway> */
    public function all(): array { return $this->gateways; }

    public function get(string $key): ?PaymentGateway
    {
        return $this->gateways[$key] ?? null;
    }

    /** The gateway configured to handle this currency, if it can. */
    public function forCurrency(string $currency): ?PaymentGateway
    {
        $currency = strtoupper($currency);
        $key = 'payments.gateway.' . $currency;

        if (!\App\Support\SettingsRegistry::has($key)) return null;

        $gateway = $this->get((string) Settings::get($key));

        if (!$gateway || !$gateway->supportsCurrency($currency)) return null;

        return $gateway;
    }

    public function defaultCurrency(): string
    {
        return (string) Settings::get('pricing.default_currency');
    }

    /** @return string[] Currencies with a route declared. */
    public function routableCurrencies(): array
    {
        return ['IRT', 'USD', 'EUR'];
    }

    /**
     * Converts into Toman for the wallet ledger, which is Toman-denominated
     * throughout. Returns null when no rate has been set, rather than
     * guessing — a wrong rate is a wrong balance.
     */
    public function toToman(int $amount, string $currency): ?int
    {
        $currency = strtoupper($currency);
        if ($currency === 'IRT') return $amount;

        $rate = match ($currency) {
            'USD' => (float) Settings::get('payments.fx.usd_to_toman'),
            'EUR' => (float) Settings::get('payments.fx.eur_to_toman'),
            default => 0.0,
        };

        return $rate > 0 ? (int) round($amount * $rate) : null;
    }
}
