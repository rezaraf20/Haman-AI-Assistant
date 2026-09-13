<?php
namespace App\Services\Payments;

/**
 * One payment gateway.
 *
 * Zarinpal was the only one for a long time and its assumptions had leaked
 * into the caller — amounts in Toman, a redirect-then-verify flow, an
 * "authority" string. Those are Zarinpal's shape, not payment's shape, so
 * they live behind this interface now: the amount carries its currency, the
 * opaque handle is just a reference, and a gateway that cannot take a given
 * currency says so rather than failing at the API.
 *
 * isConfigured() is what the settings page badges and what the manager
 * checks before routing anything to it. A gateway with no key is inert, not
 * broken: it returns a clear failure instead of throwing.
 */
interface PaymentGateway
{
    /** Stable identifier used in settings and stored on transactions. */
    public function key(): string;

    /** Whether this gateway has the credentials it needs to run. */
    public function isConfigured(): bool;

    /** @return string[] ISO codes this gateway can actually charge in. */
    public function supportedCurrencies(): array;

    public function supportsCurrency(string $currency): bool;

    /**
     * Start a payment.
     *
     * @return array{ok:bool, reference?:string, redirect_url?:string, message?:string}
     */
    public function requestPayment(int $amount, string $currency, string $callbackUrl, string $description): array;

    /**
     * Confirm it server-to-server. The amount is passed back in because no
     * gateway's redirect parameters may be trusted for it.
     *
     * @return array{ok:bool, ref_id?:string, message?:string}
     */
    public function verifyPayment(int $amount, string $currency, string $reference): array;
}
