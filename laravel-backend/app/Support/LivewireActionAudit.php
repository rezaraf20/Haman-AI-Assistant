<?php
namespace App\Support;

/**
 * Every public action method on every plain Livewire component (app/Livewire
 * — not a Filament page or widget, which sit behind panel auth already), and
 * what protects it.
 *
 * Livewire actions land on POST /livewire/update, never on the page's own
 * URL — every route-level `throttle:` middleware in this app misses them
 * completely. That exact gap was found and closed twice independently
 * (OtpLogin::sendCode() for SMS, EmailLogin::submitRegister() for tenant
 * creation) before this catalogue existed to make a third occurrence
 * mechanical rather than accidental: LivewireActionAuditTest fails the build
 * the moment a costly or security-sensitive action exists with nothing
 * listed here to protect it, or lists protection that isn't actually in the
 * code.
 */
class LivewireActionAudit
{
    /**
     * costs: generates real platform spend (SMS, LLM, a new tenant/schema) —
     *   what a scripted or distributed caller could run up a bill with.
     * security: gates authentication, a session, money, or settings — what a
     *   scripted caller could abuse even at zero direct cost (credential
     *   stuffing, OTP guessing).
     * protected_by: a literal string that must appear in the component's own
     *   source file — LivewireActionAuditTest greps for it, so this is
     *   verified, not just claimed. Null means deliberately unprotected,
     *   which then requires a non-empty unprotected_reason explaining why
     *   that is safe.
     */
    public const CATALOGUE = [
        \App\Livewire\OtpLogin::class => [
            'sendCode' => [
                'costs' => true, 'security' => true,
                'protected_by' => "'portal-otp:'",
            ],
            'resendCode' => [
                'costs' => true, 'security' => true,
                'protected_by' => "'portal-otp:'",
            ],
            'verifyCode' => [
                'costs' => false, 'security' => true,
                'protected_by' => null,
                'unprotected_reason' => 'OTP guessing is bounded by SmsService::MAX_VERIFY_ATTEMPTS (5) per code, and a fresh code requires another sendCode() call, which is itself rate-limited — not a RateLimiter key of its own.',
            ],
            'completeProfile' => [
                'costs' => true, 'security' => true,
                'protected_by' => "'register:'",
            ],
            'backToPhone' => [
                'costs' => false, 'security' => false,
                'protected_by' => null,
                'unprotected_reason' => 'Pure UI state reset, no side effect.',
            ],
        ],
        \App\Livewire\EmailLogin::class => [
            'mount' => [
                'costs' => false, 'security' => false,
                'protected_by' => null,
                'unprotected_reason' => 'Records this component\'s own render timestamp for the signup timing signal (see App\Support\SignupRisk) — sets one property, nothing else.',
            ],
            'toggleMode' => [
                'costs' => false, 'security' => false,
                'protected_by' => null,
                'unprotected_reason' => 'Pure UI state reset, no side effect.',
            ],
            'submitLogin' => [
                'costs' => false, 'security' => true,
                'protected_by' => "'login:ip:'",
            ],
            'submitRegister' => [
                'costs' => true, 'security' => true,
                'protected_by' => "'register:'",
            ],
        ],
    ];
}
