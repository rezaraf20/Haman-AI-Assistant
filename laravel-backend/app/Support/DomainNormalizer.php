<?php
namespace App\Support;

/**
 * Normalizes a customer-entered domain (BuyChatbot, ChatbotResource,
 * ChatbotController::addDomain) to the bare host string ValidateChatbotDomain
 * compares against — parse_url($request->header('Origin'), PHP_URL_HOST)
 * always returns something like "shop.com", never "https://Shop.com/" or
 * "www.shop.com". Without normalizing on the write side too, a customer
 * typing any of those variants gets their own widget permanently blocked
 * with no way to see why.
 */
class DomainNormalizer {
    public static function normalize(?string $value): ?string {
        $value = trim((string) $value);
        if ($value === '') return null;

        // parse_url needs a scheme to reliably extract the host from a value
        // that includes one (e.g. "https://shop.com/") — a bare "shop.com"
        // has no scheme, and parse_url() without one usually returns the
        // whole string as 'path', not 'host'.
        $withScheme = preg_match('#^[a-z][a-z0-9+.-]*://#i', $value) ? $value : "http://{$value}";
        $host = parse_url($withScheme, PHP_URL_HOST);
        $host = $host !== null && $host !== false ? $host : $value;

        $host = strtolower($host);
        $host = preg_replace('/^www\./', '', $host);
        $host = rtrim($host, '/');

        return $host !== '' ? $host : null;
    }
}
