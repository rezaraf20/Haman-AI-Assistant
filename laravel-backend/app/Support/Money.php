<?php
namespace App\Support;

/**
 * Every amount in the database is stored as a whole-Toman integer — this
 * class is just the single place that turns that integer into displayed
 * text, so a second currency can be added later (a real conversion layer,
 * per-tenant currency, etc.) without hunting down every `. ' تومان'` /
 * `. ' T'` string concatenation scattered across the Filament resources.
 * Toman is the only supported currency today; the $currency parameter
 * exists so callers don't need to change when a second one is added.
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

    /**
     * The public site's currency unit, translated for the current locale —
     * "تومان" in fa, "Toman" in en, never the raw settings code (IRT). One
     * setting (pricing.default_currency) drives both the plan-price grid and
     * the chatbot-type-price list on the landing page, which used to
     * disagree: one read this setting, the other always said "Toman"
     * regardless of it.
     *
     * Reuses settings.option_{code}, the label already shown next to this
     * same setting in the admin Pricing tab — one pair of translated labels,
     * not a second set invented for the landing page. A currency added to
     * SettingsRegistry's options list later without a matching settings.
     * option_{code} label falls back to the raw code instead of a
     * translation-missing string, so this never hard-breaks on a new value.
     */
    public static function currencyUnitLabel(): string {
        $code = (string) Settings::get('pricing.default_currency');
        $key = 'settings.option_' . $code;

        return \Illuminate\Support\Facades\Lang::has($key) ? __($key) : $code;
    }
}
