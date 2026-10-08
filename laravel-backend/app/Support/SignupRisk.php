<?php
namespace App\Support;

use App\Models\SignupBlock;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Signup risk signals, split into two tiers by how certain they are:
 *
 *  - blockReason(): a filled honeypot, or a submission faster than any
 *    human could plausibly manage. Both have an effectively-zero false
 *    positive rate for a sighted human (the honeypot field is never
 *    visible, never tabbable, and aria-hidden removes it from the
 *    accessibility tree too — see resources/views/landing/index.blade.php
 *    and livewire/email-login.blade.php) — certain enough to refuse the
 *    signup outright. Nothing is created; see recordBlock().
 *
 *  - compute(): everything else (an implausible user agent, a fast-but-not
 *    impossible submission). Never a reason to block on its own — the
 *    2026-10-04 investigation into three Tor-sourced trial-abuse signups
 *    found real signal here but the explicit decision was tag, never
 *    block: a false positive must never cost a real customer their
 *    signup, and an Iranian visitor behind a VPN or Tor is not a bot.
 *    Stored on tenants.signup_risk; surfaced in TenantResource's table so
 *    a human decides what, if anything, a flagged signup is worth.
 *
 * Tor/VPN exit-node detection is deliberately NOT implemented here — it
 * needs a live exit-node feed this class has no access to.
 */
class SignupRisk
{
    /**
     * The hidden form field a human never sees, tabs to, or hears from a
     * screen reader; a bot that fills every input trips this. Deliberately
     * NOT "website", "website_url", "email", "phone", or anything else a
     * browser's autofill/password-manager heuristics recognise — a name a
     * manager fills FOR a sighted human defeats the one property (zero
     * false positives) that makes blocking on it safe at all. "company_fax"
     * reads like a real, boring business-form field to a scraper without
     * matching any autofill category.
     */
    public const HONEYPOT_FIELD = 'company_fax';

    public static function compute(?string $userAgent, ?string $honeypotValue, ?float $secondsToSubmit): array
    {
        $tagThreshold = (float) Settings::get('limits.signup_too_fast_tag_seconds');

        $flags = [
            'invalid_user_agent' => self::looksInvalid($userAgent),
            'honeypot_filled'    => filled($honeypotValue),
            'submitted_too_fast' => $secondsToSubmit !== null && $secondsToSubmit >= 0 && $secondsToSubmit < $tagThreshold,
        ];

        return ['flags' => $flags, 'score' => count(array_filter($flags))];
    }

    /**
     * @return string|null the reason to refuse this signup outright, or
     *   null to let it proceed (compute()'s tag-only signals still apply).
     */
    public static function blockReason(?string $honeypotValue, ?float $secondsToSubmit): ?string
    {
        if (filled($honeypotValue)) return 'honeypot_filled';

        $blockThreshold = (float) Settings::get('limits.signup_too_fast_block_seconds');
        if ($secondsToSubmit !== null && $secondsToSubmit >= 0 && $secondsToSubmit < $blockThreshold) {
            return 'submitted_too_fast';
        }

        return null;
    }

    /**
     * The only trace a blocked attempt ever leaves — nothing else about it
     * is created. $source distinguishes which form caught it (the two
     * signup surfaces share this one class specifically so they can never
     * drift apart on what counts as a block). Never throws: a logging
     * failure must not be the reason a bot's request falls through to
     * actually creating an account.
     */
    public static function recordBlock(string $reason, string $source, ?string $ip, ?string $userAgent): void
    {
        try {
            SignupBlock::create([
                'reason' => $reason,
                'source' => $source,
                'ip' => $ip,
                'user_agent' => Str::limit((string) $userAgent, 250, ''),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning("SignupRisk::recordBlock failed ({$reason}/{$source}): " . $e->getMessage());
        }
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
