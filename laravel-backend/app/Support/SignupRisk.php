<?php
namespace App\Support;

/**
 * Free, zero-cost signup risk signals — never a reason to block a signup on
 * their own. The 2026-10-04 investigation into three Tor-sourced trial-abuse
 * signups found real signal (two of three shared one scripted-looking
 * User-Agent, all three never verified) but the explicit decision after
 * that investigation was: tag, never block — a false positive here must
 * never cost a real customer their signup, and an Iranian visitor behind a
 * VPN or Tor is not a bot. Computed once at registration, stored on
 * tenants.signup_risk (see TenantService::registerViaEmail()); surfaced in
 * TenantResource's table so a human decides what, if anything, a flagged
 * signup is worth looking at twice.
 *
 * Tor/VPN exit-node detection is deliberately NOT implemented here — it
 * needs a live exit-node feed this class has no access to. The 'flags'
 * shape below has room for a future 'known_anonymizer' key if that feed
 * ever gets wired up; until then this only ever sees what a single request
 * already carries.
 */
class SignupRisk
{
    /** The hidden form field a human never sees or fills; a bot that fills every input trips this. */
    public const HONEYPOT_FIELD = 'website';

    /** Below this many seconds between page render and submit, a human could not plausibly have filled the form. */
    private const TOO_FAST_SECONDS = 3.0;

    public static function compute(?string $userAgent, ?string $honeypotValue, ?float $secondsToSubmit): array
    {
        $flags = [
            'invalid_user_agent' => self::looksInvalid($userAgent),
            'honeypot_filled'    => filled($honeypotValue),
            'submitted_too_fast' => $secondsToSubmit !== null && $secondsToSubmit >= 0 && $secondsToSubmit < self::TOO_FAST_SECONDS,
        ];

        return ['flags' => $flags, 'score' => count(array_filter($flags))];
    }

    /**
     * Empty, or carrying a literal escape artifact (\x22, %22) in place of
     * a quote — that exact shape showed up on two of the three signups in
     * the Tor trial-abuse investigation and reads like a scripted client
     * that mis-escaped its own spoofed UA string, not a browser. Checking
     * for the bare word "Mozilla" is deliberately the full extent of the
     * "does this look like a browser at all" check — this signal exists to
     * catch a client that didn't even bother, not to detect every spoof,
     * which a determined bot defeats trivially and is not this signal's job.
     */
    private static function looksInvalid(?string $userAgent): bool
    {
        $ua = trim((string) $userAgent);
        if ($ua === '') return true;
        if (str_contains($ua, '\\x22') || str_contains($ua, '%22')) return true;
        if (!str_contains($ua, 'Mozilla')) return true;

        return false;
    }
}
