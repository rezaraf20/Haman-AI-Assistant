@php
    $rtl = app()->getLocale() === 'fa';
    $user = auth()->user();
    // The specific, common case a real customer actually hits: their own
    // tenant was suspended (User::canAccessPanel()'s 'customer' branch).
    // Everything else — a tenant user trying /admin, a support account
    // that was deactivated, any other canAccessPanel() refusal — gets the
    // generic message instead of a guess at a reason that may not apply.
    $suspended = $user && $user->tenant_id && !($user->tenant?->isAccessible() ?? true);
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $suspended ? __('common.status_suspended') : __('common.access_denied') }} — {{ config('haman.brand.name') }}</title>
    <link rel="icon" type="{{ \App\Support\Brand::markMimeType() }}" href="{{ \App\Support\Brand::markUrl() }}">
    <link rel="stylesheet" href="/css/brand.css">
    <style>
        :root { --ink: var(--brand-navy); --muted: var(--brand-muted); }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            background: #fff; color: var(--ink); font-family: var(--font-body); text-align: center; padding: 24px;
        }
        .card { max-width: 420px; }
        .mark { margin-bottom: 24px; }
        h1 { font-family: var(--font-heading); font-size: 1.5rem; margin: 0 0 12px; }
        p { color: var(--muted); line-height: 1.8; margin: 0 0 24px; }
        a.back { color: var(--brand-indigo, var(--ink)); text-decoration: none; font-weight: 600; }
    </style>
</head>
<body>
    <div class="card">
        <div class="mark">@include('partials.brand-logo', ['height' => 32])</div>
        @if ($suspended)
            <h1>{{ __('common.status_suspended') }}</h1>
            <p>{{ __('common.account_suspended_message') }}</p>
        @else
            <h1>{{ __('common.access_denied') }}</h1>
            <p>{{ __('common.access_denied_message') }}</p>
        @endif
        <a class="back" href="/">&larr; {{ config('haman.brand.name') }}</a>
    </div>
</body>
</html>
