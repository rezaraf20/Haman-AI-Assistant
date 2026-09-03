<?php
namespace App\Support;

use Carbon\Carbon;
use IntlDateFormatter;

/**
 * Locale-aware date formatting via PHP's intl extension (already built into
 * the image — see Dockerfile's `docker-php-ext-install ... intl`), so no new
 * Composer package or image rebuild is needed. Class name is historical (this
 * used to be Persian-only, hardcoded); it now branches on app()->getLocale():
 * 'fa' → Jalali/Shamsi calendar with Persian digits (۱۴۰۵), 'en' → Gregorian
 * with Latin digits, a standard yyyy-MM-dd form. Kept the same class/method
 * names rather than renaming across the ~12 call sites — the behavior change
 * is what matters, not the label.
 */
class Jalali {
    public static function date($value): ?string {
        return self::fmt($value, app()->getLocale() === 'fa' ? 'yyyy/MM/dd' : 'yyyy-MM-dd');
    }

    public static function dateTime($value): ?string {
        return self::fmt($value, app()->getLocale() === 'fa' ? 'yyyy/MM/dd HH:mm' : 'yyyy-MM-dd HH:mm');
    }

    private static function fmt($value, string $pattern): ?string {
        if (!$value) return null;
        $dt = $value instanceof \DateTimeInterface ? $value : Carbon::parse($value);

        $locale = app()->getLocale() === 'fa'
            ? 'fa_IR@calendar=persian;numbers=latn'
            : 'en_US';

        // Latin digits even for the Jalali branch — dates sit next to prices/
        // IDs/sort arrows in the same tables, and Western numerals scan
        // better mixed into that than ۱۴۰۵ would. (Persian digits elsewhere
        // in the fa UI — e.g. token/price counts — go through App\Support\
        // Numbers, not this class.)
        $formatter = new IntlDateFormatter(
            $locale,
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            app()->getLocale() === 'fa' ? 'Asia/Tehran' : 'UTC',
            IntlDateFormatter::TRADITIONAL,
            $pattern,
        );

        return $formatter->format($dt) ?: null;
    }
}
