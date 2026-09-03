<?php
namespace App\Support;

/**
 * Persian digits (۱۲۳) for the 'fa' locale, Latin digits for everything
 * else — used by App\Support\Money. Not applied inside App\Support\Jalali
 * (dates there stay Latin-digit even in fa, deliberately — see that class's
 * docblock) or retrofitted onto every existing number_format() call across
 * the Filament resources; this is the formatting primitive for new/updated
 * numeric display, not a blanket rewrite of the whole panel in one pass.
 */
class Numbers {
    private const LATIN  = ['0','1','2','3','4','5','6','7','8','9'];
    private const PERSIAN = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];

    public static function format(int|float $value, int $decimals = 0): string {
        $formatted = number_format($value, $decimals);
        return app()->getLocale() === 'fa'
            ? str_replace(self::LATIN, self::PERSIAN, $formatted)
            : $formatted;
    }
}
