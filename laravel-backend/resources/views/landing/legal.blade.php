@php
    $rtl = app()->getLocale() === 'fa';
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $title }} — {{ __('landing.brand') }}</title>
    <link rel="canonical" href="{{ \App\Support\BrandDomains::landingUrl('/legal/' . $slug) }}">
    <link rel="icon" href="{{ \App\Support\BrandDomains::landingUrl('/favicon.ico') }}" sizes="any">
    <link rel="stylesheet" href="/css/brand.css">
    <style>
        :root { --ink: var(--brand-navy); --muted: var(--brand-muted); --line: #E6E9F2; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #fff; color: var(--ink); font-family: var(--font-body); line-height: 1.8; font-size: 16px; }
        a { color: inherit; }
        .wrap { max-width: 760px; margin: 0 auto; padding: 0 20px; }
        header { padding: 24px 0; border-bottom: 1px solid var(--line); margin-bottom: 40px; }
        h1 { font-family: var(--font-heading); font-size: clamp(1.6rem, 3.2vw, 2.2rem); margin: 0 0 24px; }
        main { padding-bottom: 64px; white-space: pre-line; }
        footer { padding: 32px 0 calc(24px + env(safe-area-inset-bottom, 0px)); color: var(--muted); font-size: .85rem; border-top: 1px solid var(--line); }
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
        <h1>{{ $title }}</h1>
        {{ $body }}
        <div><a class="back" href="{{ \App\Support\BrandDomains::landingUrl('/') }}">&larr; {{ __('landing.brand') }}</a></div>
    </main>

    <footer>
        <div class="wrap" style="display:flex;gap:16px;flex-wrap:wrap">
            @foreach (\App\Support\LandingContent::LEGAL_PAGES as $other)
                <a href="{{ route('legal', $other) }}">{{ __("legal.{$other}_title") }}</a>
            @endforeach
        </div>
        @if ($contact['address'] || $contact['phone'] || $contact['email'])
            <div class="wrap" style="margin-top:10px;display:flex;gap:16px;flex-wrap:wrap">
                @if ($contact['address'])<span>{{ $contact['address'] }}</span>@endif
                @if ($contact['phone'])<span dir="ltr">{{ $contact['phone'] }}</span>@endif
                @if ($contact['email'])<span dir="ltr">{{ $contact['email'] }}</span>@endif
            </div>
        @endif
    </footer>
</body>
</html>
