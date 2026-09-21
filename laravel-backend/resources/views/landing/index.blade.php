@php
    $rtl = app()->getLocale() === 'fa';
    $otherLocale = $rtl ? 'en' : 'fa';
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ __('landing.brand') }} — {{ __('landing.hero_title') }}</title>
    <meta name="description" content="{{ __('landing.hero_subtitle') }}">
    <link rel="canonical" href="{{ \App\Support\BrandDomains::landingUrl('/') }}">

    <link rel="icon" href="{{ \App\Support\BrandDomains::landingUrl('/favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/svg+xml" href="{{ \App\Support\BrandDomains::landingUrl('/brand/hamanai-mark.svg') }}">
    <link rel="apple-touch-icon" href="{{ \App\Support\BrandDomains::landingUrl('/apple-touch-icon.png') }}">

    {{-- What a shared link looks like in a chat app or a search result.
         og:locale follows the rendered language, since the same URL serves
         both. The card is generated from resources/og/og-image.svg. --}}
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ __('landing.brand') }}">
    <meta property="og:title" content="{{ __('landing.brand') }} — {{ __('landing.hero_title') }}">
    <meta property="og:description" content="{{ __('landing.hero_subtitle') }}">
    <meta property="og:url" content="{{ \App\Support\BrandDomains::landingUrl('/') }}">
    <meta property="og:locale" content="{{ $rtl ? 'fa_IR' : 'en_US' }}">
    <meta property="og:image" content="{{ \App\Support\BrandDomains::landingUrl('/og/og-image.png') }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="{{ __('landing.brand') }} — {{ __('landing.hero_title') }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:image" content="{{ \App\Support\BrandDomains::landingUrl('/og/og-image.png') }}">
    <meta name="twitter:title" content="{{ __('landing.brand') }} — {{ __('landing.hero_title') }}">
    <meta name="twitter:description" content="{{ __('landing.hero_subtitle') }}">

    {{-- A font preload was tried here and measured out: it shaved latency
         but moved the Vazirmatn/Poppins swap earlier, into the same window
         Lighthouse measures layout shift in, and CLS went from 0 to 0.114
         for a Performance gain that didn't survive the tradeoff. Removed
         rather than kept on the theory that preloading fonts is always
         correct — here, measured, it was not. --}}
    <link rel="stylesheet" href="/css/brand.css">
    <style>
        :root {
            --ink: var(--brand-navy);
            --muted: var(--brand-muted);
            --line: #E6E9F2;
            --bg: #ffffff;
            --soft: #F6F8FC;
            --radius: 16px;
            --radius-sm: 10px;
            --shadow: 0 1px 2px rgba(0,16,48,.04), 0 8px 24px rgba(0,16,48,.06);
        }
        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            margin: 0; background: var(--bg); color: var(--ink);
            font-family: var(--font-body);
            line-height: 1.7; font-size: 16px; overflow-x: hidden;
        }
        a { color: inherit; text-decoration: none; }
        img, svg { max-width: 100%; }
        .wrap { max-width: 1120px; margin: 0 auto; padding: 0 20px; }
        h1, h2, h3, h4 { font-family: var(--font-heading); line-height: 1.3; margin: 0 0 .5em; font-weight: 600; }
        h1 { font-size: clamp(1.9rem, 4.2vw, 3.1rem); font-weight: 700; letter-spacing: -.02em; }
        h2 { font-size: clamp(1.5rem, 2.8vw, 2.1rem); }
        p { margin: 0 0 1em; }
        .muted { color: var(--muted); }
        .eyebrow { font-family: var(--font-heading); font-weight: 600; font-size: .82rem; letter-spacing: .04em; text-transform: uppercase; color: var(--brand-indigo); margin-bottom: .5em; }

        .gradient-text { background: var(--brand-gradient); -webkit-background-clip: text; background-clip: text; color: transparent; }

        /* ── nav ─────────────────────────────────────────────────────── */
        header { position: sticky; top: env(safe-area-inset-top, 0px); z-index: 40; background: rgba(255,255,255,.86); backdrop-filter: blur(10px); border-bottom: 1px solid var(--line); }
        header .wrap { display: flex; align-items: center; gap: 28px; height: 68px; }
        nav.links { display: flex; gap: 26px; margin-inline-start: auto; align-items: center; }
        nav.links a.nav-link { color: var(--muted); font-size: .94rem; font-weight: 500; font-family: var(--font-heading); }
        nav.links a.nav-link:hover { color: var(--ink); }
        .lang-switch { display: flex; gap: 4px; padding: 3px; background: var(--soft); border-radius: 99px; font-size: .82rem; font-family: var(--font-heading); font-weight: 600; }
        .lang-switch a { padding: 5px 12px; border-radius: 99px; color: var(--muted); }
        .lang-switch a.active { background: #fff; color: var(--ink); box-shadow: var(--shadow); }
        @media (max-width: 860px) { nav.links { display: none; } }

        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 11px 22px; border-radius: 10px; font-weight: 600; font-size: .95rem; font-family: var(--font-heading); border: 1px solid transparent; cursor: pointer; white-space: nowrap; transition: transform .15s ease, box-shadow .15s ease; }
        .btn-primary { background: var(--brand-gradient); color: #fff; box-shadow: 0 6px 20px rgba(72,112,248,.28); }
        .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 10px 28px rgba(72,112,248,.36); }
        .btn-ghost { border-color: var(--line); color: var(--ink); background: #fff; }
        .btn-ghost:hover { border-color: var(--brand-indigo); }
        .btn-block { width: 100%; }

        section { padding: 88px 0; }
        section.tight { padding: 64px 0; }
        /* The layout/style recalculation cost Lighthouse's mobile CPU
           throttling charges for a page this long was the single largest
           item in every profile taken while building this page (measured
           with a clean browser -- see brand.css's own note on a local
           antivirus that had been quietly inflating that number further).
           content-visibility skips layout and paint for a section until it
           is close to the viewport, which is exactly the "no CLS" case
           content-visibility promises when paired with an estimated
           contain-intrinsic-size: the browser reserves that height up
           front, so nothing jumps once the real content is measured. The
           estimates below are each section's actual rendered height at a
           common desktop width, not round numbers. */
        #features, #demo, #integrations, #pricing, #signup, #faq { content-visibility: auto; }
        #features { contain-intrinsic-size: 1200px 620px; }
        #demo { contain-intrinsic-size: 1200px 620px; }
        #integrations { contain-intrinsic-size: 1200px 460px; }
        #pricing { contain-intrinsic-size: 1200px 900px; }
        #signup { contain-intrinsic-size: 1200px 520px; }
        #faq { contain-intrinsic-size: 1200px 700px; }
        .lead { font-size: 1.15rem; color: var(--muted); max-width: 58ch; }
        .center { text-align: center; margin-inline: auto; }
        .grid { display: grid; gap: 22px; }
        @media (min-width: 760px) {
            .grid-2 { grid-template-columns: repeat(2, 1fr); }
            .grid-3 { grid-template-columns: repeat(3, 1fr); }
        }

        .card { border: 1px solid var(--line); border-radius: var(--radius); padding: 26px; background: #fff; box-shadow: var(--shadow); }
        .card h3 { font-size: 1.05rem; display: flex; align-items: center; gap: 10px; }
        .card p { margin: 0; color: var(--muted); font-size: .95rem; }
        .card .icon { width: 38px; height: 38px; border-radius: 10px; background: var(--brand-gradient); display: flex; align-items: center; justify-content: center; color: #fff; flex-shrink: 0; font-size: 1rem; }

        .soft { background: var(--soft); }

        /* ── hero ────────────────────────────────────────────────────── */
        .hero-grid { display: grid; gap: 48px; align-items: center; }
        @media (min-width: 940px) { .hero-grid { grid-template-columns: 1.05fr .95fr; } }
        .hero-actions { display: flex; gap: 12px; margin-top: 30px; flex-wrap: wrap; }

        /* Chat widget mockup: a real conversation shape, animated with CSS
           only — a typing indicator then a product card fading in — never a
           video, so it stays crisp and costs nothing to keep in sync. */
        .phone { max-width: 380px; margin-inline-start: auto; border-radius: 22px; background: #fff; border: 1px solid var(--line); box-shadow: 0 20px 60px rgba(0,16,48,.14), var(--shadow); overflow: hidden; }
        .phone-bar { background: var(--brand-gradient); padding: 16px 18px; display: flex; align-items: center; gap: 10px; color: #fff; }
        .phone-bar .dot { width: 8px; height: 8px; border-radius: 50%; background: rgba(255,255,255,.9); }
        .phone-bar strong { font-family: var(--font-heading); font-size: .95rem; }
        .phone-bar span { font-size: .74rem; opacity: .85; }
        .phone-body { padding: 18px; display: flex; flex-direction: column; gap: 12px; min-height: 300px; }
        .bubble { max-width: 84%; padding: 11px 14px; border-radius: 14px; font-size: .9rem; line-height: 1.55; animation: rise .45s ease both; }
        .bubble.user { align-self: flex-end; background: var(--soft); border-end-end-radius: 4px; }
        [dir="rtl"] .bubble.user { align-self: flex-start; }
        .bubble.bot { align-self: flex-start; background: #fff; border: 1px solid var(--line); border-end-start-radius: 4px; }
        [dir="rtl"] .bubble.bot { align-self: flex-end; }
        .bubble.bot.delay1 { animation-delay: .9s; }
        .bubble.bot.delay2 { animation-delay: 1.5s; }
        .typing { display: inline-flex; gap: 4px; align-items: center; align-self: flex-start; animation: rise .3s ease .5s both, fadeout .3s ease .85s forwards; }
        [dir="rtl"] .typing { align-self: flex-end; }
        .typing span { width: 6px; height: 6px; border-radius: 50%; background: var(--brand-indigo); opacity: .5; animation: blink 1s infinite ease-in-out; }
        .typing span:nth-child(2) { animation-delay: .15s; }
        .typing span:nth-child(3) { animation-delay: .3s; }
        .product-card { align-self: flex-start; max-width: 88%; border: 1px solid var(--line); border-radius: 14px; padding: 12px; display: flex; gap: 12px; align-items: center; animation: rise .45s ease 1.5s both; background: #fff; }
        [dir="rtl"] .product-card { align-self: flex-end; }
        .product-thumb { width: 52px; height: 52px; border-radius: 10px; background: var(--brand-gradient); flex-shrink: 0; }
        .product-card .name { font-weight: 600; font-size: .88rem; font-family: var(--font-heading); }
        .product-card .price { font-size: .84rem; color: var(--brand-indigo); font-weight: 600; }
        .product-card .stock { font-size: .74rem; color: #16794c; }
        .product-cta { margin-top: 8px; }
        .product-cta button { width: 100%; border: none; background: var(--brand-gradient); color: #fff; padding: 8px; border-radius: 8px; font-size: .82rem; font-weight: 600; font-family: var(--font-heading); }
        @keyframes rise { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes blink { 0%, 80%, 100% { opacity: .3; } 40% { opacity: 1; } }
        @keyframes fadeout { to { opacity: 0; height: 0; margin: 0; overflow: hidden; } }
        @media (prefers-reduced-motion: reduce) {
            .bubble, .typing, .product-card { animation: none !important; opacity: 1 !important; }
        }

        /* ── tabs ────────────────────────────────────────────────────── */
        .tabbar { display: inline-flex; gap: 4px; padding: 4px; background: var(--soft); border-radius: 12px; margin-bottom: 28px; flex-wrap: wrap; }
        .tabbar button { border: none; background: transparent; padding: 10px 18px; border-radius: 9px; font-family: var(--font-heading); font-weight: 600; font-size: .9rem; color: var(--muted); cursor: pointer; }
        .tabbar button.active { background: #fff; color: var(--ink); box-shadow: var(--shadow); }
        .tabpanel { display: none; }
        .tabpanel.active { display: block; animation: rise .3s ease both; }
        .demo-frame { border: 1px solid var(--line); border-radius: var(--radius); background: #fff; box-shadow: var(--shadow); overflow: hidden; }
        .demo-frame .phone-body { min-height: 220px; }
        .demo-frame img { display: block; width: 100%; height: auto; }

        /* ── report mock (trends) ───────────────────────────────────── */
        .mock-bar { background: var(--soft); border-bottom: 1px solid var(--line); padding: 12px 18px; font-size: .85rem; color: var(--muted); display: flex; justify-content: space-between; align-items: center; font-family: var(--font-heading); }
        .tag { font-size: .72rem; background: #EEF2FC; color: var(--brand-indigo); padding: 3px 10px; border-radius: 99px; font-weight: 600; }
        table { width: 100%; border-collapse: collapse; font-size: .9rem; }
        th, td { text-align: start; padding: 10px 18px; border-bottom: 1px solid var(--line); }
        th { color: var(--muted); font-weight: 600; font-size: .78rem; font-family: var(--font-heading); }
        tr:last-child td { border-bottom: 0; }
        .up { color: #16794c; font-weight: 600; }
        .down { color: #b45309; font-weight: 600; }

        /* ── integrations ───────────────────────────────────────────── */
        .integrations-row { display: flex; gap: 24px; justify-content: center; flex-wrap: wrap; margin-bottom: 44px; }
        .integration-badge { display: flex; align-items: center; gap: 12px; border: 1px solid var(--line); border-radius: var(--radius); padding: 16px 26px; background: #fff; box-shadow: var(--shadow); font-family: var(--font-heading); font-weight: 600; }
        .integration-badge .dot-icon { width: 34px; height: 34px; border-radius: 9px; background: var(--brand-gradient); }
        .steps { display: grid; gap: 22px; counter-reset: step; }
        @media (min-width: 760px) { .steps { grid-template-columns: repeat(3, 1fr); } }
        .step { position: relative; padding-inline-start: 46px; }
        [dir="rtl"] .step { padding-inline-start: 0; padding-inline-end: 46px; }
        .step::before { counter-increment: step; content: counter(step); position: absolute; inset-inline-start: 0; top: 0; width: 32px; height: 32px; border-radius: 50%; background: var(--brand-gradient); color: #fff; display: flex; align-items: center; justify-content: center; font-family: var(--font-heading); font-weight: 700; font-size: .9rem; }
        .step h4 { font-size: .98rem; margin-bottom: 4px; }
        .step p { font-size: .9rem; }

        /* ── pricing ─────────────────────────────────────────────────── */
        .price-grid { display: grid; gap: 22px; }
        @media (min-width: 760px) { .price-grid { grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); } }
        .price { position: relative; border: 1px solid var(--line); border-radius: var(--radius); padding: 30px 26px; display: flex; flex-direction: column; background: #fff; box-shadow: var(--shadow); }
        .price.popular { border-color: var(--brand-indigo); box-shadow: 0 12px 32px rgba(72,112,248,.18); }
        .price .popular-badge { position: absolute; top: -13px; inset-inline-start: 26px; background: var(--brand-gradient); color: #fff; font-family: var(--font-heading); font-size: .74rem; font-weight: 700; padding: 5px 14px; border-radius: 99px; }
        .price .amount { font-size: 2.1rem; font-weight: 700; letter-spacing: -.02em; font-family: var(--font-heading); }
        .price ul { list-style: none; padding: 0; margin: 18px 0 26px; }
        .price li { padding: 7px 0; color: var(--muted); font-size: .92rem; border-bottom: 1px dashed var(--line); }
        .price li:last-child { border-bottom: 0; }
        .price .btn { margin-top: auto; }

        .type-list { list-style: none; margin: 14px 0 0; padding: 0; max-width: 520px; }
        .type-list li { display: flex; justify-content: space-between; gap: 16px; padding: 12px 0; border-bottom: 1px solid var(--line); }
        .type-list li:last-child { border-bottom: 0; }

        .notice { border: 1px solid var(--line); border-inline-start: 3px solid #b45309; background: #FFFBF5; padding: 14px 16px; border-radius: 8px; color: var(--muted); font-size: .93rem; }

        form label { display: block; font-size: .88rem; font-weight: 600; margin-bottom: 5px; font-family: var(--font-heading); }
        form input, form textarea { width: 100%; padding: 11px 13px; border: 1px solid var(--line); border-radius: var(--radius-sm); font: inherit; margin-bottom: 14px; background: #fff; }
        form input:focus, form textarea:focus { outline: 2px solid var(--brand-indigo); outline-offset: 1px; }
        .field-error { color: #b42318; font-size: .85rem; margin: -10px 0 12px; }

        /* ── FAQ ─────────────────────────────────────────────────────── */
        details { border-bottom: 1px solid var(--line); padding: 18px 0; }
        details summary { cursor: pointer; font-weight: 600; list-style: none; font-family: var(--font-heading); display: flex; justify-content: space-between; gap: 12px; }
        details summary::-webkit-details-marker { display: none; }
        details summary::after { content: '+'; font-size: 1.3rem; color: var(--brand-indigo); flex-shrink: 0; transition: transform .2s ease; }
        details[open] summary::after { transform: rotate(45deg); }
        details p { margin: 12px 0 0; color: var(--muted); }

        /* ── final CTA ───────────────────────────────────────────────── */
        .cta-band { background: var(--brand-gradient); color: #fff; text-align: center; border-radius: 28px; padding: 64px 32px; margin: 0 20px; }
        .cta-band h2 { color: #fff; }
        .cta-band p { color: rgba(255,255,255,.92); }
        .cta-band .btn-primary { background: #fff; color: var(--brand-indigo); box-shadow: 0 8px 24px rgba(0,0,0,.18); }

        footer { padding: 48px 0 calc(32px + env(safe-area-inset-bottom, 0px)); color: var(--muted); font-size: .9rem; border-top: 1px solid var(--line); margin-top: 40px; }
        footer .wrap { display: flex; gap: 24px; flex-wrap: wrap; align-items: center; }
        footer .foot-links { display: flex; gap: 20px; margin-inline-start: auto; flex-wrap: wrap; }
        footer .foot-links a:hover { color: var(--ink); }

        @media (max-width: 400px) {
            section { padding: 56px 0; }
            .cta-band { padding: 44px 20px; margin: 0 12px; border-radius: 20px; }
        }
    </style>
</head>
<body>

<header>
    <div class="wrap">
        <a href="#top" aria-label="{{ __('landing.brand') }}">@include('partials.brand-logo', ['height' => 30])</a>
        <nav class="links">
            <a class="nav-link" href="#features">{{ __('landing.nav_features') }}</a>
            <a class="nav-link" href="#pricing">{{ __('landing.nav_pricing') }}</a>
            <a class="nav-link" href="#faq">{{ __('landing.nav_guide') }}</a>
        </nav>
        <div class="lang-switch" role="group" aria-label="Language">
            <a href="?lang=fa" class="{{ $rtl ? 'active' : '' }}">{{ __('landing.nav_lang_fa') }}</a>
            <a href="?lang=en" class="{{ $rtl ? '' : 'active' }}">{{ __('landing.nav_lang_en') }}</a>
        </div>
        <a class="nav-link" href="{{ \App\Support\BrandDomains::appUrl('/portal/login') }}">{{ __('landing.nav_login') }}</a>
        <a class="btn btn-primary" href="#signup">{{ __('landing.nav_cta') }}</a>
    </div>
</header>

<div id="top"></div>

{{-- Hero --}}
<section>
    <div class="wrap hero-grid">
        <div>
            <h1>{{ __('landing.hero_title') }}</h1>
            <p class="lead">{{ __('landing.hero_subtitle') }}</p>
            <div class="hero-actions">
                <a class="btn btn-primary" href="#signup">{{ __('landing.hero_cta') }}</a>
                <a class="btn btn-ghost" href="#demo">{{ __('landing.hero_cta_secondary') }}</a>
            </div>
            <p class="muted" style="font-size:.88rem;margin-top:22px">{{ __('landing.hero_note') }}</p>
        </div>

        <div class="phone" aria-hidden="true">
            <div class="phone-bar">
                @include('partials.brand-logo', ['height' => 20, 'variant' => 'light'])
                <div style="margin-inline-start:auto;text-align:end">
                    <div class="dot" style="display:inline-block;margin-inline-end:5px"></div>
                    <span>{{ __('landing.hero_demo_typing') }}</span>
                </div>
            </div>
            <div class="phone-body">
                <div class="bubble user">{{ __('landing.hero_demo_customer') }}</div>
                <div class="typing"><span></span><span></span><span></span></div>
                <div class="bubble bot delay1">{{ __('landing.hero_demo_bot_intro') }}</div>
                <div class="product-card delay2">
                    <div class="product-thumb"></div>
                    <div>
                        <div class="name">{{ __('landing.hero_demo_product_name') }}</div>
                        <div class="price">{{ __('landing.hero_demo_product_price') }}</div>
                        <div class="stock">{{ __('landing.hero_demo_product_stock') }}</div>
                    </div>
                </div>
                <div class="product-cta" style="animation:rise .45s ease 1.8s both;opacity:0;animation-fill-mode:forwards">
                    <button type="button" tabindex="-1">{{ __('landing.hero_demo_add_to_cart') }}</button>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- Six things it does --}}
<section id="features" class="soft">
    <div class="wrap">
        <div class="eyebrow center">{{ __('landing.nav_features') }}</div>
        <h2 class="center">{{ __('landing.features_title') }}</h2>
        <p class="lead center">{{ __('landing.features_subtitle') }}</p>
        <div class="grid grid-3" style="margin-top:40px">
            @foreach ([
                ['icon' => '💬', 'i' => 1], ['icon' => '📦', 'i' => 2], ['icon' => '⚖️', 'i' => 3],
                ['icon' => '🛒', 'i' => 4], ['icon' => '📇', 'i' => 5], ['icon' => '📈', 'i' => 6],
            ] as $f)
                <div class="card">
                    <h3><span class="icon">{{ $f['icon'] }}</span> {{ __("landing.feature{$f['i']}_title") }}</h3>
                    <p>{{ __("landing.feature{$f['i']}_body") }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- See how it works: tabbed demo --}}
<section id="demo">
    <div class="wrap">
        <div class="eyebrow center">{{ __('landing.demo_title') }}</div>
        <h2 class="center">{{ __('landing.demo_title') }}</h2>
        <p class="lead center">{{ __('landing.demo_subtitle') }}</p>

        <div class="center" style="margin-top:32px">
            <div class="tabbar" role="tablist">
                <button type="button" class="active" data-tab="sales" role="tab" aria-selected="true">{{ __('landing.demo_tab_sales') }}</button>
                <button type="button" data-tab="compare" role="tab" aria-selected="false">{{ __('landing.demo_tab_compare') }}</button>
                <button type="button" data-tab="gap" role="tab" aria-selected="false">{{ __('landing.demo_tab_gap') }}</button>
            </div>
        </div>

        <div class="tabpanel active" data-panel="sales">
            <div class="demo-frame" style="max-width:520px;margin:0 auto">
                <div class="phone-bar">@include('partials.brand-logo', ['height' => 18, 'variant' => 'light'])</div>
                <div class="phone-body">
                    <div class="bubble user">{{ __('landing.demo_sales_customer') }}</div>
                    <div class="bubble bot">{{ __('landing.demo_sales_bot') }}</div>
                </div>
            </div>
        </div>

        <div class="tabpanel" data-panel="compare">
            <div class="demo-frame" style="max-width:520px;margin:0 auto">
                <div class="phone-bar">@include('partials.brand-logo', ['height' => 18, 'variant' => 'light'])</div>
                <div class="phone-body">
                    <div class="bubble user">{{ __('landing.demo_compare_customer') }}</div>
                    <div class="bubble bot">{{ __('landing.demo_compare_bot') }}</div>
                </div>
            </div>
        </div>

        <div class="tabpanel" data-panel="gap">
            <div class="demo-frame" style="max-width:640px;margin:0 auto">
                <div class="mock-bar">
                    <span>{{ __('landing.demo_gap_title') }}</span>
                    <span class="tag">{{ __('landing.demo_gap_subtitle') }}</span>
                </div>
                <table>
                    <thead><tr><th>{{ __('landing.nav_features') }}</th><th>30d</th><th></th></tr></thead>
                    <tbody>
                        @foreach ([['price', 82, '+', 100], ['availability', 64, '+', 78], ['comparison', 41, '-', 50], ['shipping', 23, '+', 28]] as [$topic, $n, $dir, $w])
                            <tr>
                                <td>{{ $topic }}</td>
                                <td>
                                    <span style="display:flex;align-items:center;gap:8px">
                                        <span style="height:7px;border-radius:4px;background:var(--brand-gradient);opacity:.8;width:{{ $w }}px"></span>{{ $n }}
                                    </span>
                                </td>
                                <td class="{{ $dir === '+' ? 'up' : 'down' }}">{{ $dir }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p class="muted" style="padding:14px 18px;font-size:.85rem;margin:0">{{ __('landing.demo_gap_note') }}</p>
            </div>
        </div>
    </div>
</section>
<script>
    (function () {
        var buttons = document.querySelectorAll('.tabbar [data-tab]');
        var panels = document.querySelectorAll('.tabpanel[data-panel]');
        buttons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                buttons.forEach(function (b) { b.classList.remove('active'); b.setAttribute('aria-selected', 'false'); });
                panels.forEach(function (p) { p.classList.remove('active'); });
                btn.classList.add('active');
                btn.setAttribute('aria-selected', 'true');
                document.querySelector('.tabpanel[data-panel="' + btn.dataset.tab + '"]').classList.add('active');
            });
        });
    })();
</script>

{{-- Integrations --}}
<section id="integrations" class="soft">
    <div class="wrap">
        <div class="eyebrow center">{{ __('landing.integrations_title') }}</div>
        <h2 class="center">{{ __('landing.integrations_title') }}</h2>
        <p class="lead center">{{ __('landing.integrations_subtitle') }}</p>

        <div class="integrations-row">
            <div class="integration-badge"><span class="dot-icon"></span> {{ __('landing.integrations_wp') }}</div>
            <div class="integration-badge"><span class="dot-icon"></span> {{ __('landing.integrations_woo') }}</div>
        </div>

        <div class="steps">
            @foreach ([1, 2, 3] as $i)
                <div class="step">
                    <h4>{{ __("landing.integrations_step{$i}_title") }}</h4>
                    <p>{{ __("landing.integrations_step{$i}_body") }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- Pricing, read from the plans an admin published --}}
<section id="pricing">
    <div class="wrap">
        <div class="eyebrow center">{{ __('landing.pricing_title') }}</div>
        <h2 class="center">{{ __('landing.pricing_title') }}</h2>
        <p class="lead center">{{ __('landing.pricing_subtitle') }}</p>

        @if ($plans->isEmpty())
            <p class="notice" style="margin-top:28px">{{ __('landing.pricing_empty') }}</p>
        @else
            <div class="price-grid" style="margin-top:40px">
                @foreach ($plans as $plan)
                    @php $isPopular = $popularSlug !== '' && $plan->slug === $popularSlug; @endphp
                    <div class="price {{ $isPopular ? 'popular' : '' }}">
                        @if ($isPopular)
                            <span class="popular-badge">{{ __('landing.pricing_popular') }}</span>
                        @endif
                        <h3>{{ $plan->name }}</h3>
                        <div class="amount">
                            {{ number_format((float) $plan->price_monthly) }}
                            <span class="muted" style="font-size:.9rem;font-weight:400">{{ $currency }}</span>
                        </div>
                        <div class="muted" style="font-size:.85rem">{{ __('landing.pricing_per_month') }}</div>

                        @if ($plan->description)
                            <p class="muted" style="margin-top:12px;font-size:.92rem">{{ $plan->description }}</p>
                        @endif

                        <ul>
                            <li>{{ __('landing.pricing_chatbots', ['count' => number_format((int) $plan->max_chatbots)]) }}</li>
                            <li>{{ __('landing.pricing_tokens', ['count' => number_format((int) $plan->max_tokens_monthly)]) }}</li>
                            @if ($plan->max_documents)
                                <li>{{ __('landing.pricing_documents', ['count' => number_format((int) $plan->max_documents)]) }}</li>
                            @endif
                            @if ($plan->max_domains)
                                <li>{{ __('landing.pricing_domains', ['count' => number_format((int) $plan->max_domains)]) }}</li>
                            @endif
                            @foreach (($plan->features ?? []) as $feature)
                                <li>{{ $feature }}</li>
                            @endforeach
                        </ul>

                        <a class="btn {{ $isPopular ? 'btn-primary' : 'btn-ghost' }}" href="#signup">{{ __('landing.pricing_cta') }}</a>
                    </div>
                @endforeach
            </div>

            <p class="muted center" style="margin-top:24px;font-size:.9rem">{{ __('landing.pricing_enterprise_note') }}</p>
        @endif

        @if ($chatbotTypes->isNotEmpty())
            <div class="types" style="margin-top:48px;max-width:560px;margin-inline:auto">
                <h3 style="font-size:1.1rem;text-align:center">{{ __('landing.types_title') }}</h3>
                <p class="muted" style="font-size:.92rem;text-align:center">{{ __('landing.types_subtitle') }}</p>
                <ul class="type-list">
                    @foreach ($chatbotTypes as $type)
                        <li>
                            <span>{{ $type->name }}</span>
                            <strong>{{ number_format((int) $type->price_toman) }}
                                <span class="muted" style="font-weight:400;font-size:.85rem">{{ __('landing.toman') }}</span>
                            </strong>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
</section>

{{-- Signup --}}
<section id="signup" class="soft">
    <div class="wrap" style="max-width:560px">
        <h2 class="center">{{ __('landing.signup_title') }}</h2>
        <p class="lead center">{{ __('landing.signup_subtitle') }}</p>

        @if (session('landing_status'))
            <p class="notice" style="border-inline-start-color:#16794c;background:#F4FBF7">{{ session('landing_status') }}</p>
        @endif

        @if (! $emailSignup)
            <p class="notice">{{ __('landing.signup_email_disabled') }}</p>
            <p class="center"><a class="btn btn-primary" href="{{ \App\Support\BrandDomains::appUrl('/portal/login?method=phone') }}">{{ __('landing.signup_phone_alternative') }}</a></p>
        @else
            <div class="card" style="margin-top:24px">
                <form method="POST" action="{{ route('landing.register') }}">
                    @csrf
                    <label for="name">{{ __('landing.signup_name') }}</label>
                    <input id="name" name="name" value="{{ old('name') }}" required maxlength="255">
                    @error('name') <div class="field-error">{{ $message }}</div> @enderror

                    <label for="email">{{ __('landing.signup_email') }}</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" required maxlength="255">
                    @error('email') <div class="field-error">{{ $message }}</div> @enderror

                    <label for="password">{{ __('landing.signup_password') }}</label>
                    <input id="password" name="password" type="password" required minlength="8">
                    @error('password') <div class="field-error">{{ $message }}</div> @enderror

                    <label for="password_confirmation">{{ __('landing.signup_password_confirm') }}</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" required minlength="8">

                    <button class="btn btn-primary btn-block" type="submit">{{ __('landing.signup_submit') }}</button>
                </form>
            </div>

            <p class="muted center" style="font-size:.9rem;margin-top:16px">
                <a href="{{ \App\Support\BrandDomains::appUrl('/portal/login?method=phone') }}">{{ __('landing.signup_phone_alternative') }}</a>
                &nbsp;·&nbsp;
                <a href="{{ \App\Support\BrandDomains::appUrl('/portal/login') }}">{{ __('landing.signup_have_account') }}</a>
            </p>
        @endif
    </div>
</section>

{{-- FAQ --}}
<section id="faq">
    <div class="wrap" style="max-width:760px">
        <h2 class="center">{{ __('landing.faq_title') }}</h2>
        <div style="margin-top:28px">
            @foreach ($faq as $item)
                <details>
                    <summary>{{ $item['q'] }}</summary>
                    <p>{{ $item['a'] }}</p>
                </details>
            @endforeach
        </div>
    </div>
</section>

{{-- Final CTA --}}
<section class="tight">
    <div class="wrap">
        <div class="cta-band">
            <h2>{{ __('landing.final_cta_title') }}</h2>
            <p class="lead center" style="max-width:52ch">{{ __('landing.final_cta_subtitle') }}</p>
            <a class="btn btn-primary" href="#signup">{{ __('landing.final_cta_button') }}</a>
        </div>
    </div>
</section>

<footer>
    <div class="wrap">
        @include('partials.brand-logo', ['height' => 26])
        <span class="muted">{{ __('landing.footer_tagline') }}</span>
        <span class="foot-links">
            <a href="{{ \App\Support\BrandDomains::appUrl('/portal/login') }}">{{ __('landing.footer_login') }}</a>
            <a href="#faq">{{ __('landing.footer_contact') }}</a>
        </span>
    </div>
    <div class="wrap muted" style="margin-top:18px;font-size:.85rem">© {{ date('Y') }} {{ __('landing.brand') }} — {{ __('landing.footer_rights') }}</div>
</footer>

</body>
</html>
