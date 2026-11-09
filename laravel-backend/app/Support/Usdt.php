<?php
namespace App\Support;

/**
 * USDT amounts, exactly. No float ever touches one.
 *
 *   micro — what the platform stores and charges: integer millionths of a
 *           USDT (19.99 USDT = 19_990_000), a bigint.
 *   base  — what the chain moves: USDT on BNB Smart Chain has 18 decimals,
 *           so 1 micro = 10^12 base. Kept as a decimal string (bcmath),
 *           since it does not fit in 64 bits.
 */
final class Usdt
{
    public const DECIMALS = 6;
    public const MICRO = 1_000_000;
    public const BASE_PER_MICRO = '1000000000000';   // 10^12

    /** BNB Smart Chain mainnet and its USDT (BEP20) contract — verified against the chain: chainId 0x38, decimals() 18. */
    public const CHAIN_ID = 56;
    public const CONTRACT = '0x55d398326f99059ff775485246999027b3197955';
    public const TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

    /** "19.99" / "19,99" / "۱۹٫۹۹" → 19_990_000. Null when it is not a non-negative amount with at most six decimals. */
    public static function toMicro(string|int|null $amount): ?int
    {
        if ($amount === null) return null;
        $s = strtr(trim((string) $amount), ['٫' => '.', ',' => '.', '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3',
            '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
        if (!preg_match('/^(\d{1,12})(?:\.(\d{0,6}))?$/', $s, $m)) return null;
        return (int) $m[1] * self::MICRO + (int) str_pad($m[2] ?? '', self::DECIMALS, '0');
    }

    /** 19_990_000 → "19.99"; 20_004_213 → "20.004213". Always at least two decimals. */
    public static function plain(int $micro): string
    {
        $sign = $micro < 0 ? '-' : '';
        $micro = abs($micro);
        $frac = rtrim(str_pad((string) ($micro % self::MICRO), self::DECIMALS, '0', STR_PAD_LEFT), '0');
        return $sign . intdiv($micro, self::MICRO) . '.' . str_pad($frac, 2, '0');
    }

    /** "20.004213 USDT" — Latin digits on purpose: this is the number a customer types into a wallet app. */
    public static function format(int $micro): string
    {
        return self::plain($micro) . ' USDT';
    }

    public static function microToBase(int $micro): string
    {
        return bcmul((string) $micro, self::BASE_PER_MICRO, 0);
    }

    /** Whole micro-USDT in a base amount, rounded down; null when it does not fit a bigint. */
    public static function baseToMicro(string $base): ?int
    {
        $micro = bcdiv($base, self::BASE_PER_MICRO, 0);
        return bccomp($micro, (string) PHP_INT_MAX) > 0 ? null : (int) $micro;
    }

    /** Whether a base amount is a whole number of micro-USDT — only such an amount can equal a requested one exactly. */
    public static function isWholeMicro(string $base): bool
    {
        return bcmod($base, self::BASE_PER_MICRO, 0) === '0';
    }

    /** "0x…" uint256 → decimal string. */
    public static function hexToDec(string $hex): string
    {
        $hex = strtolower(ltrim(str_starts_with($hex, '0x') ? substr($hex, 2) : $hex, '0'));
        $dec = '0';
        foreach (str_split($hex === '' ? '0' : $hex) as $digit) {
            $dec = bcadd(bcmul($dec, '16', 0), (string) hexdec($digit), 0);
        }
        return $dec;
    }

    /** A 32-byte log topic → its 20-byte address, lowercase. */
    public static function topicToAddress(string $topic): string
    {
        return '0x' . strtolower(substr($topic, -40));
    }

    /** The 32-byte topic for an address — what eth_getLogs filters the recipient by. */
    public static function addressToTopic(string $address): string
    {
        return '0x' . str_repeat('0', 24) . strtolower(substr($address, 2));
    }

    public static function isAddress(?string $address): bool
    {
        return is_string($address) && (bool) preg_match('/^0x[0-9a-fA-F]{40}$/', $address);
    }

    public static function txUrl(string $txHash): string
    {
        return 'https://bscscan.com/tx/' . $txHash;
    }
}
