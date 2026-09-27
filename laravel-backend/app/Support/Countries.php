<?php
namespace App\Support;

/**
 * The signup form's country picker — the one input that decides a tenant's
 * currency for the rest of their time in the portal (see Tenant::currency()).
 * Iran maps to Toman; every other country here maps to Euro. The list itself
 * doesn't need to be exhaustive for that to work correctly (only the IR/
 * not-IR distinction matters today), but a country dropdown that can't find
 * your country reads as broken, so this covers the countries an Iranian
 * SaaS's international signups actually come from rather than a token
 * handful.
 */
class Countries
{
    public const IRAN = 'IR';

    /** @return array<string, string> ISO 3166-1 alpha-2 => English name, Iran first. */
    public static function all(): array
    {
        return [
            'IR' => 'Iran',
            'AE' => 'United Arab Emirates',
            'AF' => 'Afghanistan',
            'AL' => 'Albania',
            'AM' => 'Armenia',
            'AR' => 'Argentina',
            'AT' => 'Austria',
            'AU' => 'Australia',
            'AZ' => 'Azerbaijan',
            'BD' => 'Bangladesh',
            'BE' => 'Belgium',
            'BG' => 'Bulgaria',
            'BH' => 'Bahrain',
            'BR' => 'Brazil',
            'CA' => 'Canada',
            'CH' => 'Switzerland',
            'CN' => 'China',
            'CY' => 'Cyprus',
            'CZ' => 'Czechia',
            'DE' => 'Germany',
            'DK' => 'Denmark',
            'DZ' => 'Algeria',
            'EG' => 'Egypt',
            'ES' => 'Spain',
            'FI' => 'Finland',
            'FR' => 'France',
            'GB' => 'United Kingdom',
            'GE' => 'Georgia',
            'GR' => 'Greece',
            'HK' => 'Hong Kong',
            'HU' => 'Hungary',
            'ID' => 'Indonesia',
            'IE' => 'Ireland',
            'IL' => 'Israel',
            'IN' => 'India',
            'IQ' => 'Iraq',
            'IT' => 'Italy',
            'JO' => 'Jordan',
            'JP' => 'Japan',
            'KR' => 'South Korea',
            'KW' => 'Kuwait',
            'KZ' => 'Kazakhstan',
            'LB' => 'Lebanon',
            'MY' => 'Malaysia',
            'NL' => 'Netherlands',
            'NO' => 'Norway',
            'NZ' => 'New Zealand',
            'OM' => 'Oman',
            'PK' => 'Pakistan',
            'PL' => 'Poland',
            'PT' => 'Portugal',
            'QA' => 'Qatar',
            'RO' => 'Romania',
            'RU' => 'Russia',
            'SA' => 'Saudi Arabia',
            'SE' => 'Sweden',
            'SG' => 'Singapore',
            'SY' => 'Syria',
            'TH' => 'Thailand',
            'TJ' => 'Tajikistan',
            'TM' => 'Turkmenistan',
            'TR' => 'Turkey',
            'TW' => 'Taiwan',
            'UA' => 'Ukraine',
            'US' => 'United States',
            'UZ' => 'Uzbekistan',
            'ZA' => 'South Africa',
            'OTHER' => 'Other',
        ];
    }

    public static function isValid(string $code): bool
    {
        return array_key_exists(strtoupper($code), self::all());
    }

    public static function isIran(?string $code): bool
    {
        return strtoupper((string) $code) === self::IRAN;
    }
}
