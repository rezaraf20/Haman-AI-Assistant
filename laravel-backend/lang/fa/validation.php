<?php
// OTP-flow and other custom-message validation strings — distinct from
// Laravel's own stock lang/{locale}/validation.php (rule-name messages like
// "required"), which this app doesn't publish/override.
return [
    'otp_expired' => 'کد منقضی شده است. دوباره درخواست دهید.',
    'otp_incorrect' => 'کد وارد شده صحیح نیست.',
    'otp_max_attempts' => 'تعداد تلاش‌های مجاز تمام شد. کد جدید درخواست دهید.',
    'otp_resent' => 'کد تایید مجدداً ارسال شد.',
    'otp_sent' => 'کد تایید ارسال شد.',
    'phone_format' => 'شماره موبایل را به‌صورت صحیح وارد کنید (مثال: 09123456789).',
    'please_wait' => 'لطفاً کمی صبر کنید و دوباره تلاش کنید.',
    'request_otp_first' => 'ابتدا کد تایید را درخواست کنید.',
    'sms_send_failed' => 'ارسال پیامک ناموفق بود. لطفاً بعداً تلاش کنید.',
];
