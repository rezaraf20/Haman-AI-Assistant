@php
    $locale = app()->getLocale();
    $isRtl = $locale === 'fa';
    $fontFamily = $isRtl ? "'Vazirmatn','Tahoma',sans-serif" : "'Inter','Segoe UI',sans-serif";
    $otherLocale = $isRtl ? 'en' : 'fa';
    $otherLocaleLabel = $isRtl ? __('panel.language_en') : __('panel.language_fa');
    $otherMethod = $method === 'phone' ? 'email' : 'phone';
    $otherMethodLabel = $otherMethod === 'phone'
        ? __('common.use_phone_instead')
        : __('common.use_email_instead');
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('common.auth_title') }} — Haman AI</title>
    @livewireStyles
</head>
<body style="margin:0;min-height:100vh;background:#F1F5F9;font-family:{{ $fontFamily }};">
    <div style="max-width:380px;margin:16px auto 0;padding:0 24px;display:flex;justify-content:space-between;font-size:13px;">
        <a href="{{ url()->current() }}?lang={{ $otherLocale }}" style="color:#64748B;text-decoration:none;">{{ $otherLocaleLabel }}</a>
        <a href="{{ url()->current() }}?method={{ $otherMethod }}" style="color:#64748B;text-decoration:none;">{{ $otherMethodLabel }}</a>
    </div>

    @if ($method === 'phone')
        @livewire('otp-login')
    @else
        @livewire('email-login')
    @endif

    @livewireScripts
</body>
</html>
