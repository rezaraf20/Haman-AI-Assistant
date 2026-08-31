<?php
namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\OtpVerification;
use Illuminate\Support\Facades\Http;

class SmsService {
    // How long an OTP stays valid, and the minimum gap between two OTP
    // requests for the same phone — both are anti-abuse basics, not just UX.
    const CODE_TTL_MINUTES = 5;
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
                'message' => 'لطفاً کمی صبر کنید و دوباره تلاش کنید.',
                'retry_after' => self::RESEND_COOLDOWN_SECONDS - $recent->created_at->diffInSeconds(now()),
            ];
        }

        $code = (string) random_int(10000, 99999);

        OtpVerification::create([
            'phone'      => $phone,
            'code'       => $code,
            'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
        ]);

        $sent = $this->send($phone, $code);
        if (!$sent['ok']) {
            return ['ok' => false, 'message' => 'ارسال پیامک ناموفق بود. لطفاً بعداً تلاش کنید.'];
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
            return ['ok' => false, 'message' => 'ابتدا کد تایید را درخواست کنید.'];
        }
        if ($otp->expires_at->isPast()) {
            return ['ok' => false, 'message' => 'کد منقضی شده است. دوباره درخواست دهید.'];
        }
        if ($otp->attempts >= self::MAX_VERIFY_ATTEMPTS) {
            return ['ok' => false, 'message' => 'تعداد تلاش‌های مجاز تمام شد. کد جدید درخواست دهید.'];
        }
        if (!hash_equals($otp->code, $code)) {
            $otp->increment('attempts');
            return ['ok' => false, 'message' => 'کد وارد شده صحیح نیست.'];
        }

        $otp->update(['consumed_at' => now()]);
        return ['ok' => true];
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
    private function send(string $phone, string $code): array {
        $settings = PlatformSetting::current();
        if (!$settings->melipayamak_username || !$settings->melipayamak_password) {
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
                    'text'     => "کد تایید هامان AI: {$code}\nاین کد تا " . self::CODE_TTL_MINUTES . " دقیقه معتبر است.",
                    'isflash'  => false,
                ]);
            }
            $body = $resp->json();
            return ['ok' => $resp->successful() && (int) ($body['RetStatus'] ?? 0) === 1];
        } catch (\Throwable $e) {
            report($e);
            return ['ok' => false];
        }
    }
}
