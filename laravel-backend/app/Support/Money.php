<?php
namespace App\Support;

/**
 * Every amount in the database is stored as a whole-Toman integer. Two
 * unrelated audiences read it back:
 *
 *  - The admin panel (and every internal ledger operation — WalletService,
 *    the *_toman columns themselves) is Toman-only, always, on purpose. See
 *    toman()/format() below, both untouched by anything in this class past
 *    this point. The platform's own books are kept in Toman regardless of
 *    what any visitor or tenant sees.
 *  - A visitor on the public site, or a tenant in their own portal, sees
 *    Toman or Euro depending on locale (the site) or the country they gave
 *    at signup (the portal) — see currencyForLocale() and Tenant::currency().
 *    convert()/unitLabel()/display() below are that second, display-only
 *    layer: a Euro number is always a live conversion of the one real
 *    Toman value, never a second value stored anywhere, so there is nothing
 *    for the two to drift apart from.
 *
 * There is no live FX fetch (payments.fx.mode's 'source' option is
 * registered but has no consumer anywhere in this codebase) — the rate is
 * whatever an admin has typed into payments.fx.eur_to_toman. Unset (0),
 * convert() returns null rather than dividing by zero or inventing a rate,
 * and every caller here treats null as "not available in this currency
 * yet", not as a display bug to paper over.
 */
class Money {
    public static function toman(int $amountToman): string {
        return self::format($amountToman, 'toman');
    }

    public static function format(int $amountToman, string $currency = 'toman'): string {
        return match ($currency) {
            'toman' => Numbers::format($amountToman) . ' ' . __('common.toman'),
            default => Numbers::format($amountToman),
        };
    }

    /** fa sees Toman, everything else sees Euro — a locale choice, not the admin-wide pricing.default_currency setting (that setting still exists, for payment-gateway routing — see PaymentGatewayManager). */
    public static function currencyForLocale(?string $locale = null): string {
        return ($locale ?? app()->getLocale()) === 'fa' ? 'IRT' : 'EUR';
    }

    /**
     * The raw converted number, no formatting or unit — null when EUR/USD's
     * FX rate isn't configured. IRT is always available (identity, no rate
     * needed) so this only ever returns null for a currency that actually
     * requires one.
     */
    public static function convert(int $amountToman, string $currency): ?float {
        $currency = strtoupper($currency);
        if ($currency === 'IRT') return (float) $amountToman;

        $rate = match ($currency) {
            'USD' => (float) Settings::get('payments.fx.usd_to_toman'),
            'EUR' => (float) Settings::get('payments.fx.eur_to_toman'),
            default => 0.0,
        };
        if ($rate <= 0) return null;

        // Rounded to a whole unit, not fractional Euros/Dollars — the only
        // gateway calls that exist (PaymentGatewayManager::toToman(),
        // PaymentGateway::requestPayment()) are int-amount contracts too, so
        // a displayed price and an actually-charged amount can never
        // silently disagree down to the cent.
        return round($amountToman / $rate);
    }

    /**
     * The translated unit word for a currency code — reuses settings.
     * option_{code}, the same label already shown next to pricing.
     * default_currency in the admin Pricing tab, so there is one pair of
     * translated currency names, not two. Falls back to the raw code for a
     * currency nobody has labelled yet, rather than a translation-missing
     * string.
     */
    public static function unitLabel(string $currency): string {
        $key = 'settings.option_' . strtoupper($currency);
        return \Illuminate\Support\Facades\Lang::has($key) ? __($key) : strtoupper($currency);
    }

    /** convert() + unitLabel() in one call, for callers that don't need the number and the unit styled separately. Null exactly when convert() is. */
    public static function display(int $amountToman, string $currency): ?string {
        $amount = self::convert($amountToman, $currency);
        return $amount === null ? null : Numbers::format($amount) . ' ' . self::unitLabel($currency);
    }

    private static ?string $currentTenantCurrencyCache = null;

    /**
     * Every Customer\Pages\* price/balance display: the currently
     * logged-in tenant's own currency, falling back to plain Toman on the
     * one currency (EUR/USD with no FX rate set) display() can't show —
     * never a blank amount. The one repeated line across Wallet, BuyChatbot,
     * BuyTokens, MyChatbots and the dashboard widget, so a fix to that
     * fallback only ever needs to happen here.
     *
     * The currency lookup itself is memoized per request — a customer
     * dashboard can call this several times over (wallet stat, chatbot
     * list, buy-tokens table…), and re-querying the tenants table on every
     * one of them is exactly the kind of per-call cost that blew
     * DashboardWidgetsTest's query budget once before (see Brand::$cache
     * for the same fix on a different class). forget() clears it the same
     * way Settings::forget() and Brand::forget() do, for tests that change
     * the underlying tenant row mid-test.
     */
    public static function forCurrentTenant(int $amountToman): string {
        if (self::$currentTenantCurrencyCache === null) {
            // Tenant::find() on the plain tenant_id column, not auth()->
            // user()->tenant — that relation isn't eager-loaded here, and
            // Model::preventLazyLoading() (AppServiceProvider) only allows
            // a lazy relation load in production, throwing everywhere else
            // this runs, tests included.
            $tenantId = auth()->user()?->tenant_id;
            self::$currentTenantCurrencyCache = ($tenantId ? \App\Models\Tenant::find($tenantId)?->currency() : null) ?? 'IRT';
        }

        return self::display($amountToman, self::$currentTenantCurrencyCache) ?? self::toman($amountToman);
    }

    public static function forget(): void {
        self::$currentTenantCurrencyCache = null;
    }
}
