<?php
namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\OtpVerification;
use Illuminate\Support\Facades\Http;
use App\Support\Settings;
use Illuminate\Support\Facades\{Cache, Log};

class SmsService {
    // How long an OTP stays valid now comes from the settings page
    // (limits.otp_ttl_minutes). The cooldown and the verify-attempt ceiling
    // stay constants deliberately: both are anti-abuse floors rather than
    // tuning knobs, and neither was in the list of numbers to surface.
    const RESEND_COOLDOWN_SECONDS = 60;
    const MAX_VERIFY_ATTEMPTS = 5;

    /**
     * @return array{ok:bool, message?:string, retry_after?:int}
     */
    public function sendOtp(string $phone): array {
        $recent = OtpVerification::where('phone', $phone)
            ->whereNull('consumed_at')
            ->latest('created_at')
            ->first();

        if ($recent && $recent->created_at->diffInSeconds(now()) < self::RESEND_COOLDOWN_SECONDS) {
            return [
                'ok' => false,
                'message' => __('validation.please_wait'),
                'retry_after' => self::RESEND_COOLDOWN_SECONDS - $recent->created_at->diffInSeconds(now()),
            ];
        }

        $code = (string) random_int(10000, 99999);

        OtpVerification::create([
            'phone'      => $phone,
            'code'       => $code,
            'expires_at' => now()->addMinutes(Settings::get('limits.otp_ttl_minutes')),
        ]);

        $sent = $this->send($phone, $code);
        if (!$sent['ok']) {
            return ['ok' => false, 'message' => __('validation.sms_send_failed')];
        }
        return ['ok' => true];
    }

    /**
     * @return array{ok:bool, message?:string}
     */
    public function verifyOtp(string $phone, string $code): array {
        $otp = OtpVerification::where('phone', $phone)
            ->whereNull('consumed_at')
            ->latest('created_at')
            ->first();

        if (!$otp) {
            return ['ok' => false, 'message' => __('validation.request_otp_first')];
        }
        if ($otp->expires_at->isPast()) {
            return ['ok' => false, 'message' => __('validation.otp_expired')];
        }
        if ($otp->attempts >= self::MAX_VERIFY_ATTEMPTS) {
            return ['ok' => false, 'message' => __('validation.otp_max_attempts')];
        }
        if (!hash_equals($otp->code, $code)) {
            $otp->increment('attempts');
            return ['ok' => false, 'message' => __('validation.otp_incorrect')];
        }

        $otp->update(['consumed_at' => now()]);
        return ['ok' => true];
    }

    /**
     * get_order_status (doc-04) — sends an already-minted code for the chat
     * order-status flow. Deliberately NOT routed through sendOtp(): that
     * method owns the portal-login otp_verifications table and its own
     * cooldown, while this flow stores its code (hashed) in
     * order_status_otps and enforces much stricter, tenant-billed caps of
     * its own in ChatController. All this shares is the transport.
     */
    public function sendCode(string $phone, string $code): bool {
        return $this->send($phone, $code)['ok'];
    }

    /**
     * Melipayamak's legacy REST API (username+password), NOT the newer
     * console.melipayamak.com GUID-key endpoint — verified directly against
     * the live account: the console-key endpoint rejected these credentials
     * ("کلید کنسول معتبر نیست"), this one accepted them
     * ({"RetStatus":1,"StrRetStatus":"Ok"}). Don't "modernize" this without
     * re-testing against a real send first.
     *
     * $code is the raw OTP digits — used as-is for pattern sends (Melipayamak
     * fills it into the pre-approved template server-side), or wrapped in a
     * plain-text message for simple sends.
     *
     * @return array{ok:bool}
     */
    /**
     * Platform-wide and per-tenant daily ceilings.
     *
     * Every send spends the merchant's money, and the per-contact and
     * per-IP limits in ChatController bound one abuser's reach — they do
     * not bound the total. These do: a bug or a distributed abuser cannot
     * run up an unbounded bill in a day.
     *
     * A cap of 0 means no ceiling, which is the only way to express "off"
     * for a limit whose natural value is a positive number.
     */
    private function withinDailyCaps(): bool {
        $today = now()->toDateString();

        $platformCap = (int) Settings::get('sms.daily_cap_platform');
        if ($platformCap > 0) {
            $sent = (int) Cache::get("sms:sent:platform:{$today}", 0);
            if ($sent >= $platformCap) {
                Log::warning("SmsService: platform daily SMS cap ({$platformCap}) reached; send refused.");
                return false;
            }
        }

        $tenantId = auth()->user()?->tenant_id ?? request()->attributes->get('tenant_id');
        $tenantCap = (int) Settings::get('sms.daily_cap_per_tenant');
        if ($tenantId && $tenantCap > 0) {
            $sent = (int) Cache::get("sms:sent:tenant:{$tenantId}:{$today}", 0);
            if ($sent >= $tenantCap) {
                Log::warning("SmsService: tenant {$tenantId} daily SMS cap ({$tenantCap}) reached; send refused.");
                return false;
            }
        }

        return true;
    }

    /** Counters expire on their own; nothing needs to reset them at midnight. */
    private function countSend(): void {
        $today = now()->toDateString();
        $untilMidnight = max(60, now()->endOfDay()->diffInSeconds(now()));

        Cache::put("sms:sent:platform:{$today}",
            (int) Cache::get("sms:sent:platform:{$today}", 0) + 1, $untilMidnight);

        $tenantId = auth()->user()?->tenant_id ?? request()->attributes->get('tenant_id');
        if ($tenantId) {
            Cache::put("sms:sent:tenant:{$tenantId}:{$today}",
                (int) Cache::get("sms:sent:tenant:{$tenantId}:{$today}", 0) + 1, $untilMidnight);
        }
    }

    private function send(string $phone, string $code): array {
        $settings = PlatformSetting::current();
        if (!$settings->melipayamak_username || !$settings->melipayamak_password) {
            return ['ok' => false];
        }

        if (!$this->withinDailyCaps()) {
            return ['ok' => false];
        }

        try {
            if ($settings->melipayamak_use_pattern && $settings->melipayamak_pattern_id) {
                // Pattern/"BaseServiceNumber" send — required by Iranian
                // carriers for a lot of OTP traffic, since plain SMS from a
                // shared number is commonly filtered as advertising. `text`
                // holds the pattern's %variable% values, semicolon-separated;
                // this app's patterns are assumed single-variable (just the code).
                $resp = Http::timeout(15)->post('https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber', [
                    'username' => $settings->melipayamak_username,
                    'password' => $settings->melipayamak_password,
                    'to'       => $phone,
                    'bodyId'   => (int) $settings->melipayamak_pattern_id,
                    'text'     => $code,
                ]);
            } else {
                $resp = Http::timeout(15)->post('https://rest.payamak-panel.com/api/SendSMS/SendSMS', [
                    'username' => $settings->melipayamak_username,
                    'password' => $settings->melipayamak_password,
                    'to'       => $phone,
                    'from'     => $settings->melipayamak_sender,
                    // Actual SMS body text, out of the panel's fa/en system:
                    // phone numbers matching Iran's 09XXXXXXXXX format are
                    // the only ones this OTP flow ever sends to.
                    'text'     => "کد تایید هامان AI: {$code}\nاین کد تا " . Settings::get('limits.otp_ttl_minutes') . " دقیقه معتبر است.", // i18n:widget
                    'isflash'  => false,
                ]);
            }
            $body = $resp->json();
            $ok = $resp->successful() && (int) ($body['RetStatus'] ?? 0) === 1;

            // Only a send that actually happened counts against the cap.
            if ($ok) $this->countSend();

            return ['ok' => $ok];
        } catch (\Throwable $e) {
            report($e);
            return ['ok' => false];
        }
    }
}
