@php
    $rtl = app()->getLocale() === 'fa';
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ __('auth_email.reset_password_title') }} — {{ __('landing.brand') }}</title>
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
        h1 { font-family: var(--font-heading); font-size: clamp(1.4rem, 3vw, 1.8rem); margin: 0 0 24px; }
        label { display: block; font-size: .85rem; margin-bottom: 6px; color: var(--muted); }
        input[type=password] { width: 100%; padding: 10px 12px; border: 1px solid var(--line); border-radius: 8px; font-size: 15px; margin-bottom: 16px; font-family: inherit; }
        .btn { display: inline-block; padding: 10px 20px; border-radius: 8px; border: 0; background: var(--brand-indigo, var(--ink)); color: #fff; font-size: .95rem; cursor: pointer; }
        .field-error { color: #C0314B; font-size: .82rem; margin: -10px 0 12px; }
    </style>
</head>
<body>
    <header>
        <div class="wrap">
            <a href="{{ \App\Support\BrandDomains::landingUrl('/') }}">@include('partials.brand-logo', ['height' => 26])</a>
        </div>
    </header>

    <main class="wrap">
        <h1>{{ __('auth_email.reset_password_title') }}</h1>

        {{--
            Posts back to the EXACT same signed path+query this page itself
            was reached with (expires/signature carried as hidden fields) —
            the 'signed' middleware checks the request's own URL, so the
            POST has to reconstruct the identical signature-bearing query
            string rather than getting a signature of its own. See
            PasswordResetController::showResetForm()'s own comment.
        --}}
        <form method="POST" action="{{ route('password.reset.store', array_merge(['id' => $id, 'hash' => $hash, 'nonce' => $nonce], $query)) }}">
            @csrf
            <label for="reset-password">{{ __('common.password') }}</label>
            <input type="password" id="reset-password" name="password" required minlength="8" autofocus>
            @error('password') <div class="field-error">{{ $message }}</div> @enderror

            <label for="reset-password-confirm">{{ __('common.confirm_password') }}</label>
            <input type="password" id="reset-password-confirm" name="password_confirmation" required minlength="8">

            <button class="btn" type="submit">{{ __('common.confirm_button') }}</button>
        </form>
    </main>
</body>
</html>
