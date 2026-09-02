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
}
