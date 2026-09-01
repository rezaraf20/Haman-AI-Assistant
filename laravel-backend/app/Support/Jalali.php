<?php
namespace App\Support;

use Carbon\Carbon;
use IntlDateFormatter;

/**
 * Persian (Jalali/Shamsi) calendar date formatting via PHP's intl extension
 * (already built into the image — see Dockerfile's `docker-php-ext-install
 * ... intl`), so no new Composer package or image rebuild is needed. Latin
 * digits on purpose: dates sit next to prices/IDs/sort arrows in the same
 * tables, and Western numerals scan better mixed into that than ۱۴۰۵ would.
 */
class Jalali {
    public static function date($value): ?string {
        return self::fmt($value, 'yyyy/MM/dd');
    }

    public static function dateTime($value): ?string {
        return self::fmt($value, 'yyyy/MM/dd HH:mm');
    }

    private static function fmt($value, string $pattern): ?string {
        if (!$value) return null;
        $dt = $value instanceof \DateTimeInterface ? $value : Carbon::parse($value);

        $formatter = new IntlDateFormatter(
            'fa_IR@calendar=persian;numbers=latn',
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            'Asia/Tehran',
            IntlDateFormatter::TRADITIONAL,
            $pattern,
        );

        return $formatter->format($dt) ?: null;
    }
}
