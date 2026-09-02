@php
    $locale = app()->getLocale();
    $isRtl = $locale === 'fa';
    $fontFamily = $isRtl ? "'Vazirmatn','Tahoma',sans-serif" : "'Inter','Segoe UI',sans-serif";
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
    @livewire('otp-login')
    @livewireScripts
</body>
</html>
