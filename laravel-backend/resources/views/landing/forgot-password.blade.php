@php
    $rtl = app()->getLocale() === 'fa';
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ __('auth_email.forgot_password_title') }} — {{ __('landing.brand') }}</title>
    <link rel="icon" href="{{ \App\Support\BrandDomains::landingUrl('/favicon.ico') }}" sizes="any">
    <link rel="icon" type="{{ \App\Support\Brand::markMimeType() }}" href="{{ \App\Support\BrandDomains::landingUrl(\App\Support\Brand::markUrl()) }}">
    <link rel="stylesheet" href="/css/brand.css">
    <style>
        :root { --ink: var(--brand-navy); --muted: var(--brand-muted); --line: #E6E9F2; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #fff; color: var(--ink); font-family: var(--font-body); line-height: 1.8; font-size: 16px; }
        a { color: inherit; }
        .wrap { max-width: 420px; margin: 0 auto; padding: 0 20px; }
        header { padding: 24px 0; margin-bottom: 40px; }
        main { padding-bottom: 64px; }
        h1 { font-family: var(--font-heading); font-size: clamp(1.4rem, 3vw, 1.8rem); margin: 0 0 12px; }
        p { color: var(--muted); }
        .notice { margin: 0 0 24px; padding: 12px 16px; border-radius: 8px; border-inline-start: 3px solid var(--brand-indigo, var(--ink)); background: #F4F6FF; font-size: .9rem; }
        label { display: block; font-size: .85rem; margin-bottom: 6px; color: var(--muted); }
        input[type=email] { width: 100%; padding: 10px 12px; border: 1px solid var(--line); border-radius: 8px; font-size: 15px; margin-bottom: 16px; font-family: inherit; }
        .btn { display: inline-block; padding: 10px 20px; border-radius: 8px; border: 0; background: var(--brand-indigo, var(--ink)); color: #fff; font-size: .95rem; cursor: pointer; }
        .back { display: inline-block; margin-top: 24px; font-size: .9rem; color: var(--brand-indigo, var(--ink)); }
    </style>
</head>
<body>
    <header>
        <div class="wrap">
            <a href="{{ \App\Support\BrandDomains::landingUrl('/') }}">@include('partials.brand-logo', ['height' => 26])</a>
        </div>
    </header>

    <main class="wrap">
        @if (session('landing_status'))
            <p class="notice">{{ session('landing_status') }}</p>
        @endif

        <h1>{{ __('auth_email.forgot_password_title') }}</h1>
        <p>{{ __('auth_email.forgot_password_body') }}</p>

        <form method="POST" action="{{ route('password.email') }}">
            @csrf
            <label for="forgot-email">{{ __('common.email') }}</label>
            <input type="email" id="forgot-email" name="email" required autofocus>
            <button class="btn" type="submit">{{ __('common.confirm_button') }}</button>
        </form>

        <div>
            <a class="back" href="{{ \App\Support\BrandDomains::appUrl('/portal/login') }}">&larr; {{ __('common.login_link') }}</a>
        </div>
    </main>
</body>
</html>
