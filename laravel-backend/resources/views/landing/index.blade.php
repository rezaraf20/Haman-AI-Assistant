@php
    $rtl = app()->getLocale() === 'fa';
    // Every visible string goes through one of these two. $t: plain text,
    // Blade-escaped by {{ }}, with any [[...]] emphasis marker dropped.
    // $h: a heading — escaped FIRST inside LandingContent::rich(), and only
    // then is [[...]] turned into <span class="hl">. Nothing an admin types
    // in the Site content page can reach this page as HTML.
    $t = fn (string $key, array $replace = []) => \App\Support\LandingContent::plain(__("landing.{$key}", $replace));
    $h = fn (string $key) => \App\Support\LandingContent::rich(__("landing.{$key}"));
    $metaTitle = $t('brand') . ' — ' . $t('hero_title');
    $steps = $rtl ? ['۱', '۲', '۳'] : ['1', '2', '3'];
    $img = fn (string $slot) => \App\Support\LandingContent::imageUrl($slot);
    $alt = fn (string $slot) => \App\Support\LandingContent::imageAlt($slot);
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $metaTitle }}</title>
    <meta name="description" content="{{ $t('hero_subtitle') }}">
    <link rel="canonical" href="{{ \App\Support\BrandDomains::landingUrl('/') }}">

    <link rel="icon" href="{{ \App\Support\BrandDomains::landingUrl('/favicon.ico') }}" sizes="any">
    <link rel="icon" type="{{ \App\Support\Brand::markMimeType() }}" href="{{ \App\Support\BrandDomains::landingUrl(\App\Support\Brand::markUrl()) }}">
    <link rel="apple-touch-icon" href="{{ \App\Support\BrandDomains::landingUrl('/apple-touch-icon.png') }}">

    {{-- What a shared link looks like in a chat app or a search result.
         og:locale follows the rendered language, since the same URL serves
         both. The card is generated from resources/og/og-image.svg. --}}
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $t('brand') }}">
    <meta property="og:title" content="{{ $metaTitle }}">
    <meta property="og:description" content="{{ $t('hero_subtitle') }}">
    <meta property="og:url" content="{{ \App\Support\BrandDomains::landingUrl('/') }}">
    <meta property="og:locale" content="{{ $rtl ? 'fa_IR' : 'en_US' }}">
    <meta property="og:image" content="{{ \App\Support\BrandDomains::landingUrl('/og/og-image.png') }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="{{ $metaTitle }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:image" content="{{ \App\Support\BrandDomains::landingUrl('/og/og-image.png') }}">
    <meta name="twitter:title" content="{{ $metaTitle }}">
    <meta name="twitter:description" content="{{ $t('hero_subtitle') }}">

    {{-- A font preload was tried here and measured out: it shaved latency
         but moved the Vazirmatn/Poppins swap earlier, into the same window
         Lighthouse measures layout shift in, and CLS went from 0 to 0.114
         for a Performance gain that didn't survive the tradeoff. Removed
         rather than kept on the theory that preloading fonts is always
         correct — here, measured, it was not. --}}
    <link rel="stylesheet" href="/css/brand.css">
    <style>
        /* Violet on purpose (the 2026-11 redesign): the real brand gradient
           stays on the mark, the step numbers and the ticks; #6D28D9 is the
           interactive colour — buttons, links, emphasis — at 6.1:1 on white.
           Light only, like the rest of the public site. */
        :root {
            --brand: #6D28D9;
            --brand-deep: #2E1065;
            --grad: linear-gradient(135deg, #0098F8 0%, #4870F8 50%, #7050F8 100%);
            --ink: #16123A;
            --muted: #615C8A;
            --subtle: #8E89B3;
            --surface: #FFFFFF;
            --tint: #F6F3FE;
            --tint-2: #EDE8FD;
            --line: #E7E2F8;
            --line-strong: #D6CEF3;
            --ok: #0D9488;
            --shadow: 0 1px 2px rgba(22,18,58,.04), 0 12px 28px -12px rgba(46,16,101,.18);
            --shadow-lg: 0 2px 4px rgba(22,18,58,.04), 0 28px 60px -20px rgba(46,16,101,.26);
            --r: 18px; --r-lg: 26px;
            --fa: "Vazirmatn", ui-sans-serif, system-ui, "Segoe UI", sans-serif;
            --en: "Poppins", "Vazirmatn", ui-sans-serif, system-ui, "Segoe UI", sans-serif;
            color-scheme: light;
        }
        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; scroll-padding-top: 76px; }
        body {
            margin: 0; background: var(--surface); color: var(--ink);
            font-family: var(--fa); font-size: 16px; line-height: 1.85;
            -webkit-font-smoothing: antialiased;
        }
        html[dir="ltr"] body { font-family: var(--en); line-height: 1.7; }
        img { max-width: 100%; }
        .band { width: 100%; }
        .band.tinted { background: linear-gradient(180deg, #FBFAFF 0%, var(--tint) 50%, #FBFAFF 100%); }
        .band.plain { background: var(--surface); }
        .wrap {
            max-width: 1120px; margin-inline: auto;
            padding-left: max(16px, env(safe-area-inset-left, 0px));
            padding-right: max(16px, env(safe-area-inset-right, 0px));
            padding-block: 84px;
        }
        .wrap.tight { padding-block: 56px; }
        h1, h2, h3 { margin: 0; text-wrap: balance; line-height: 1.45; font-weight: 800; letter-spacing: -.01em; }
        html[dir="ltr"] h1, html[dir="ltr"] h2, html[dir="ltr"] h3 { font-weight: 700; }
        h1 { font-size: clamp(28px, 4.6vw, 46px); line-height: 1.4; }
        h2 { font-size: clamp(23px, 3.1vw, 34px); }
        h3 { font-size: 18px; font-weight: 700; line-height: 1.6; }
        p { margin: 0; }
        a { color: var(--brand); text-decoration: none; }
        a:hover { text-decoration: underline; }
        :focus-visible { outline: 2.5px solid var(--brand); outline-offset: 3px; border-radius: 6px; }
        .hl { color: var(--brand); }
        .lead { color: var(--muted); font-size: clamp(15px, 1.5vw, 17.5px); }
        .muted { color: var(--muted); }

        .eyebrow { display: inline-block; background: var(--tint-2); color: var(--brand);
            font-size: 13px; font-weight: 700; padding: 7px 16px; border-radius: 999px; line-height: 1.6; }
        .sec-head { display: flex; flex-direction: column; align-items: center; text-align: center; gap: 14px; margin-bottom: 46px; }
        .sec-head p { max-width: 62ch; }

        .btn { display: inline-flex; align-items: center; gap: 9px; justify-content: center;
            font-family: inherit; font-size: 15.5px; font-weight: 700; line-height: 1.35; text-align: center; max-width: 100%;
            padding: 15px 28px; border-radius: 14px; border: 1.5px solid transparent; cursor: pointer;
            transition: transform .16s ease, box-shadow .16s ease, background .16s ease; }
        .btn:hover { text-decoration: none; transform: translateY(-1.5px); }
        .btn-primary { background: var(--brand); color: #fff; box-shadow: 0 10px 22px -8px rgba(109,40,217,.6); }
        .btn-primary:hover { background: #5B21B6; }
        .btn-ghost { background: var(--surface); color: var(--ink); border-color: var(--line-strong); box-shadow: var(--shadow); }
        .btn-ghost:hover { border-color: var(--brand); color: var(--brand); }
        .btn-light { background: #fff; color: var(--brand-deep); }
        .btn-outline-light { background: transparent; color: #fff; border-color: rgba(255,255,255,.4); }
        .btn-outline-light:hover { background: rgba(255,255,255,.1); }
        .btn-block { width: 100%; }
        .btn svg { width: 17px; height: 17px; flex: none; }
        html[dir="ltr"] .btn .arrow { transform: scaleX(-1); }

        /* ── nav ─────────────────────────────────────────────────────── */
        .nav-band { position: sticky; top: env(safe-area-inset-top, 0px); z-index: 20;
            background: rgba(255,255,255,.88); backdrop-filter: blur(14px); border-bottom: 1px solid var(--line); }
        body::before { content: ""; position: fixed; inset: 0 0 auto; height: env(safe-area-inset-top, 0px); z-index: 21; background: #fff; }
        .nav { display: flex; align-items: center; gap: 24px; padding-block: 14px; max-width: 1120px; margin-inline: auto;
            padding-left: max(16px, env(safe-area-inset-left, 0px)); padding-right: max(16px, env(safe-area-inset-right, 0px)); }
        .brand { display: flex; align-items: center; gap: 11px; font-weight: 800; font-size: 19px; color: var(--ink); flex: none; }
        .brand:hover { text-decoration: none; }
        .brand .mark { width: 34px; height: 40px; flex: none; object-fit: contain; }
        .nav-links { display: flex; gap: 26px; margin-inline-start: auto; font-size: 15px; font-weight: 600; }
        .nav-links a { color: var(--muted); }
        .nav-links a:hover { color: var(--brand); text-decoration: none; }
        .nav .btn { padding: 11px 20px; font-size: 14.5px; white-space: nowrap; flex: none; }
        .lang { display: inline-flex; background: var(--tint-2); border-radius: 11px; padding: 3px; gap: 2px; flex: none; }
        .lang a { font-size: 13.5px; font-weight: 700; padding: 7px 13px; border-radius: 8px; line-height: 1.3;
            color: var(--brand); transition: background .15s ease; }
        .lang a:hover { text-decoration: none; background: rgba(255,255,255,.6); }
        .lang a[aria-current="true"] { background: #fff; box-shadow: 0 1px 3px rgba(46,16,101,.18); }
        @media (max-width: 980px) { .nav-links { display: none; } .lang { margin-inline-start: auto; } }
        @media (max-width: 620px) {
            .nav .btn-ghost { display: none; } .nav { gap: 10px; }
            .nav .btn { padding: 11px 15px; font-size: 14px; } .lang a { padding: 6px 10px; font-size: 13px; }
        }
        @media (max-width: 430px) { .brand > span { display: none; } .nav { gap: 8px; } }

        /* ── hero ────────────────────────────────────────────────────── */
        .hero-band { background:
            radial-gradient(900px 480px at 88% -5%, #EDE4FF 0%, rgba(237,228,255,0) 62%),
            radial-gradient(760px 420px at 6% 8%, #E3EEFF 0%, rgba(227,238,255,0) 60%),
            linear-gradient(180deg, #FBFAFF, #FFFFFF); border-bottom: 1px solid var(--line); }
        .hero { display: grid; grid-template-columns: 1.05fr .95fr; gap: 52px; align-items: center; padding-block: 72px 84px; }
        .hero-copy { display: flex; flex-direction: column; align-items: flex-start; gap: 22px; min-width: 0; }
        .hero-actions { display: flex; gap: 13px; flex-wrap: wrap; }
        .hero-note { font-size: 14px; color: var(--subtle); display: flex; align-items: center; gap: 8px; }
        .hero-note svg { width: 16px; height: 16px; flex: none; color: var(--ok); }
        .hero-art { position: relative; min-width: 0; }
        @media (max-width: 900px) { .hero { grid-template-columns: 1fr; gap: 42px; padding-block: 48px 60px; } .hero-art { order: -1; } }

        /* An admin-replaceable picture (Site content → images). Always a real
           image — a shipped default until one is uploaded — so the public
           page never shows an empty frame. Fixed aspect ratio + width/height
           attributes: nothing shifts when it loads. */
        .media { position: relative; width: 100%; border-radius: var(--r-lg); overflow: hidden;
            background: linear-gradient(145deg, #EFE8FF, #E0EBFF); border: 1px solid var(--line); box-shadow: var(--shadow-lg); }
        .media img { display: block; width: 100%; height: 100%; object-fit: cover; }
        .media.hero-media { aspect-ratio: 1 / 1; max-width: 460px; margin-inline: auto; }
        .media.wide { aspect-ratio: 4 / 3; }

        .float { position: absolute; background: #fff; border: 1px solid var(--line); border-radius: 16px;
            box-shadow: var(--shadow-lg); padding: 13px 16px; font-size: 14px; font-weight: 600; display: flex; align-items: center; gap: 10px; line-height: 1.6; }
        .float-q { top: 6%; inset-inline-start: -6px; max-width: 210px; }
        .float-a { bottom: 7%; inset-inline-end: -6px; max-width: 240px; flex-direction: column; align-items: stretch; gap: 11px; padding: 14px; }
        .av { width: 30px; height: 30px; border-radius: 9px; background: var(--tint-2); display: grid; place-items: center; flex: none; color: var(--brand); }
        .av svg { width: 17px; height: 17px; }
        .av.user { background: #E3EBFF; color: #3B5BDB; }
        .mini-card { display: flex; gap: 11px; align-items: center; background: var(--tint); border-radius: 12px; padding: 10px; }
        .mini-thumb { width: 46px; height: 46px; border-radius: 9px; background: linear-gradient(140deg, #D5CEFB, #C2D6FB); flex: none; display: grid; place-items: center; color: var(--brand); }
        .mini-thumb svg { width: 22px; height: 22px; }
        .mini-meta { min-width: 0; display: flex; flex-direction: column; gap: 3px; }
        .mini-name { font-size: 12.5px; font-weight: 700; line-height: 1.5; }
        .mini-row { display: flex; align-items: center; gap: 7px; font-size: 12px; flex-wrap: wrap; }
        .mini-price { font-weight: 800; color: var(--brand); }
        .stock { display: inline-flex; align-items: center; gap: 4px; color: var(--ok); font-weight: 700; font-size: 11.5px; }
        .stock svg { width: 12px; height: 12px; }
        .mini-cta { background: var(--brand); color: #fff; border-radius: 10px; text-align: center; font-size: 12.5px; font-weight: 700; padding: 9px; }
        @media (max-width: 560px) {
            /* Deviation from the design file, which stacked both cards under
               the image: on a phone that pushed the headline ~750px down,
               off the first screen. The hero image already shows the same
               conversation, so the decorative cards step aside instead. */
            .float-q, .float-a { display: none; }
            .media.hero-media { aspect-ratio: 4 / 3; }
            .hero { gap: 28px; padding-block: 28px 52px; }
        }

        /* ── split sections ──────────────────────────────────────────── */
        .split { display: grid; grid-template-columns: 1fr 1fr; gap: 56px; align-items: center; }
        .split.rev .split-art { order: -1; }
        .split-copy { display: flex; flex-direction: column; align-items: flex-start; gap: 16px; min-width: 0; }
        .split-art { min-width: 0; }
        @media (max-width: 900px) { .split { grid-template-columns: 1fr; gap: 38px; } .split.rev .split-art { order: 0; } }

        .panel { background: #fff; border: 1px solid var(--line); border-radius: var(--r-lg); box-shadow: var(--shadow-lg); overflow: hidden; }
        .panel-top { display: flex; align-items: center; gap: 10px; padding: 14px 18px; border-bottom: 1px solid var(--line); background: linear-gradient(180deg, #FBFAFF, #fff); }
        .panel-title { font-size: 14.5px; font-weight: 700; }
        .panel-body { padding: 18px; display: flex; flex-direction: column; gap: 14px; background: var(--tint); }
        .bubble { max-width: 86%; padding: 12px 15px; border-radius: 16px; font-size: 14px; line-height: 1.8; }
        .bubble.user { margin-inline-start: auto; background: var(--brand); color: #fff; border-end-end-radius: 5px; }
        .bubble.bot { background: #fff; border: 1px solid var(--line); border-end-start-radius: 5px; }
        .cards-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
        .pcard { background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 10px; display: flex; flex-direction: column; gap: 8px; min-width: 0; }
        .pcard-img { aspect-ratio: 1 / 1; border-radius: 10px; background: linear-gradient(140deg, #E1DBFC, #CFDCFB); display: grid; place-items: center; color: var(--brand); }
        .pcard-img svg { width: 26px; height: 26px; }
        .pcard-name { font-size: 11.5px; font-weight: 700; line-height: 1.55; }
        .pcard-price { font-size: 12px; font-weight: 800; color: var(--brand); }
        @media (max-width: 430px) { .cards-row { grid-template-columns: repeat(2, 1fr); } }

        /* ── cards / grid ────────────────────────────────────────────── */
        .grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; }
        @media (max-width: 900px) { .grid-3 { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 620px) { .grid-3 { grid-template-columns: 1fr; } }
        .card { background: #fff; border: 1px solid var(--line); border-radius: var(--r); padding: 26px 24px;
            display: flex; flex-direction: column; gap: 12px; box-shadow: var(--shadow); min-width: 0;
            transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease; }
        .card:hover { transform: translateY(-3px); box-shadow: var(--shadow-lg); border-color: var(--line-strong); }
        .card p { font-size: 14.5px; color: var(--muted); line-height: 1.85; }
        .well { width: 48px; height: 48px; border-radius: 14px; background: var(--tint-2); display: grid; place-items: center; color: var(--brand); flex: none; }
        .well svg { width: 23px; height: 23px; }

        .proof { display: flex; flex-direction: column; gap: 14px; width: 100%; }
        .proof-item { background: #fff; border: 1px solid var(--line); border-radius: var(--r); padding: 20px 22px; box-shadow: var(--shadow); }
        .proof-tag { font-size: 12px; font-weight: 800; color: var(--brand); margin-bottom: 12px; display: block; }
        .qa { display: flex; flex-direction: column; gap: 10px; }
        .qa .bubble { max-width: 100%; }

        .rail { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; }
        @media (max-width: 820px) { .rail { grid-template-columns: 1fr; } }
        .step { background: #fff; border: 1px solid var(--line); border-radius: var(--r); padding: 26px 24px 24px;
            display: flex; flex-direction: column; gap: 11px; box-shadow: var(--shadow); min-width: 0; }
        .step-n { width: 38px; height: 38px; border-radius: 12px; background: var(--grad); color: #fff;
            display: grid; place-items: center; font-weight: 800; font-size: 16px; flex: none; }
        .step p { font-size: 14.5px; color: var(--muted); }
        .platforms { display: flex; gap: 10px; justify-content: center; margin-top: 30px; flex-wrap: wrap; }
        .chip { display: inline-flex; align-items: center; gap: 8px; background: #fff; border: 1px solid var(--line-strong);
            border-radius: 999px; padding: 10px 20px; font-size: 14px; font-weight: 700; box-shadow: var(--shadow); }
        .chip svg { width: 17px; height: 17px; color: var(--brand); }

        .checks { display: flex; flex-direction: column; gap: 14px; margin-top: 12px; }
        .check { display: flex; gap: 12px; align-items: flex-start; font-size: 15px; line-height: 1.8; }
        .tick { width: 24px; height: 24px; border-radius: 8px; background: var(--grad); color: #fff; display: grid; place-items: center; flex: none; margin-top: 4px; }
        .tick svg { width: 13px; height: 13px; }

        /* ── pricing (plans from the DB — only the look changed) ────────── */
        .price-grid { display: grid; gap: 20px; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); }
        @media (max-width: 620px) { .price-grid { grid-template-columns: 1fr; } }
        .price { position: relative; background: #fff; border: 1px solid var(--line); border-radius: var(--r-lg); padding: 30px 26px;
            display: flex; flex-direction: column; box-shadow: var(--shadow); min-width: 0; }
        .price.popular { border: 2px solid var(--brand); box-shadow: var(--shadow-lg); }
        .price h3 { font-size: 19px; }
        .price .popular-badge { position: absolute; top: -14px; inset-inline-start: 24px; background: var(--brand); color: #fff;
            font-size: 12.5px; font-weight: 700; padding: 5px 14px; border-radius: 999px; line-height: 1.5; }
        .price .amount { font-size: 32px; font-weight: 800; letter-spacing: -.02em; margin-top: 10px; line-height: 1.3; color: var(--ink); }
        .price .unit { font-size: 14px; font-weight: 600; color: var(--muted); letter-spacing: 0; }
        .price ul { list-style: none; padding: 0; margin: 18px 0 26px; display: flex; flex-direction: column; gap: 10px; }
        .price li { display: flex; gap: 10px; align-items: flex-start; color: var(--muted); font-size: 14.5px; line-height: 1.7; }
        .price li::before { content: ""; width: 18px; height: 18px; border-radius: 6px; flex: none; margin-top: 3px;
            background: var(--tint-2) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%236D28D9' stroke-width='3.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M20 6L9 17l-5-5'/%3E%3C/svg%3E") center / 11px no-repeat; }
        .price .btn { margin-top: auto; }
        .pricing-notes { text-align: center; margin-top: 24px; font-size: 14.5px; color: var(--muted); display: flex; flex-direction: column; gap: 6px; }

        .notice { background: #fff; border: 1px solid var(--line); border-inline-start: 4px solid #B45309; padding: 14px 18px; border-radius: 12px; color: var(--muted); font-size: 15px; max-width: 640px; margin-inline: auto; }
        .notice.ok { border-inline-start-color: var(--ok); }

        /* Two markups, shown one at a time by width via plain CSS — sticky
           table cells are unreliable across engines, so below 640px it is
           one card per tool instead of a horizontally scrolled table. */
        .sub-title { text-align: center; margin-top: 56px; margin-bottom: 20px; font-size: 20px; }
        .tool-compare-wrap { overflow-x: auto; background: #fff; border: 1px solid var(--line); border-radius: var(--r); box-shadow: var(--shadow); -webkit-overflow-scrolling: touch; }
        .tool-compare { width: 100%; min-width: 480px; border-collapse: separate; border-spacing: 0; font-size: 14.5px; }
        .tool-compare th, .tool-compare td { padding: 13px 16px; text-align: center; border-bottom: 1px solid var(--line); white-space: nowrap; }
        .tool-compare tbody tr:last-child th, .tool-compare tbody tr:last-child td { border-bottom: 0; }
        .tool-compare thead th { font-weight: 700; color: var(--ink); background: var(--tint); }
        .tool-compare th:first-child, .tool-compare td:first-child { text-align: start; font-weight: 600; }
        .tool-yes { color: var(--ok); font-weight: 800; }
        .tool-no { color: var(--line-strong); }
        .tool-compare-cards { display: none; }
        .tool-compare-card { background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 14px 16px; margin-top: 10px; }
        .tool-compare-card .name { font-weight: 700; font-size: 15px; margin-bottom: 6px; }
        .tool-compare-card .row { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 5px 0; font-size: 14px; color: var(--muted); }
        @media (max-width: 640px) { .tool-compare-wrap { display: none; } .tool-compare-cards { display: block; } }

        .types { margin: 48px auto 0; max-width: 560px; text-align: center; }
        .types h3 { font-size: 19px; }
        .type-list { list-style: none; margin: 16px 0 0; padding: 6px 20px; background: #fff; border: 1px solid var(--line); border-radius: var(--r); box-shadow: var(--shadow); text-align: start; }
        .type-list li { display: flex; justify-content: space-between; gap: 16px; padding: 12px 0; border-bottom: 1px solid var(--line); }
        .type-list li:last-child { border-bottom: 0; }

        /* ── signup ──────────────────────────────────────────────────── */
        .signup { max-width: 560px; margin-inline: auto; }
        .form-card { background: #fff; border: 1px solid var(--line); border-radius: var(--r-lg); padding: 28px 26px; box-shadow: var(--shadow-lg); }
        form label { display: block; font-size: 14px; font-weight: 700; margin-bottom: 6px; }
        form input, form select { width: 100%; padding: 12px 14px; border: 1.5px solid var(--line-strong); border-radius: 12px; font: inherit; font-size: 15px; margin-bottom: 16px; background: #fff; color: var(--ink); }
        form input:focus, form select:focus { outline: 2.5px solid var(--brand); outline-offset: 1px; border-color: var(--brand); }
        .field-help { font-size: 13px; color: var(--muted); margin: -10px 0 14px; }
        .field-error { color: #B42318; font-size: 13.5px; margin: -10px 0 14px; }
        .signup-alt { text-align: center; font-size: 14.5px; margin-top: 18px; color: var(--muted); }

        /* ── FAQ ─────────────────────────────────────────────────────── */
        .faq { display: flex; flex-direction: column; gap: 12px; max-width: 820px; margin-inline: auto; }
        details { background: #fff; border: 1px solid var(--line); border-radius: var(--r); box-shadow: var(--shadow); overflow: hidden; }
        details[open] { border-color: var(--line-strong); }
        summary { cursor: pointer; list-style: none; padding: 19px 22px; font-weight: 700; font-size: 15.5px;
            display: flex; align-items: center; gap: 13px; transition: color .15s ease; line-height: 1.7; }
        summary::-webkit-details-marker { display: none; }
        summary:hover { color: var(--brand); }
        .caret { width: 22px; height: 22px; border-radius: 7px; background: var(--tint-2); color: var(--brand);
            display: grid; place-items: center; flex: none; margin-inline-start: auto; transition: transform .2s ease; }
        .caret svg { width: 12px; height: 12px; }
        details[open] .caret { transform: rotate(180deg); }
        details p { padding: 0 22px 20px; color: var(--muted); font-size: 14.5px; line-height: 1.9; }

        /* ── final CTA ───────────────────────────────────────────────── */
        .cta-band { background:
            radial-gradient(620px 300px at 82% 18%, rgba(112,80,248,.45) 0%, rgba(112,80,248,0) 68%),
            radial-gradient(circle at 12% 82%, rgba(0,152,248,.26) 0%, rgba(0,152,248,0) 56%),
            linear-gradient(135deg, #2E1065 0%, #1B1046 55%, #140B33 100%);
            position: relative; overflow: hidden; }
        .cta-band::after { content: ""; position: absolute; inset: 0;
            background-image: radial-gradient(rgba(255,255,255,.22) 1px, transparent 1px);
            background-size: 26px 26px; opacity: .4; pointer-events: none; }
        .cta { display: grid; grid-template-columns: 1.25fr auto; gap: 36px; align-items: center; position: relative; z-index: 1; }
        .cta h2 { color: #fff; }
        .cta .hl { color: #C4B5FD; }
        .cta p { color: #CEC4EE; font-size: 16px; margin-top: 12px; max-width: 56ch; }
        .cta-actions { display: flex; gap: 13px; flex-wrap: wrap; }
        @media (max-width: 860px) { .cta { grid-template-columns: 1fr; gap: 26px; } }

        /* ── footer ──────────────────────────────────────────────────── */
        .foot-band { background: #120A2E; color: #A79BD0; }
        .foot { display: flex; justify-content: space-between; align-items: center; gap: 22px; flex-wrap: wrap; padding-block: 34px 18px; font-size: 14px; }
        .foot a { color: #CEC4EE; }
        .foot-links { display: flex; gap: 8px 22px; flex-wrap: wrap; }
        .foot .brand { color: #fff; font-size: 17px; }
        .foot-meta { display: flex; gap: 8px 22px; flex-wrap: wrap; justify-content: space-between; font-size: 13.5px;
            padding-block: 18px calc(28px + env(safe-area-inset-bottom, 0px)); border-top: 1px solid rgba(255,255,255,.08); }
        .foot-contact { display: flex; gap: 8px 18px; flex-wrap: wrap; }

        /* Off-screen sections skip layout and paint until they are near the
           viewport — the largest single cost in every mobile Lighthouse
           profile of this page. Each estimate is the section's real rendered
           height at a common desktop width, so nothing jumps on arrival. */
        #features, #demo, #why, #how, #pricing, #signup, #faq { content-visibility: auto; }
        #features { contain-intrinsic-size: auto 900px; }
        #demo { contain-intrinsic-size: auto 820px; }
        #why { contain-intrinsic-size: auto 640px; }
        #how { contain-intrinsic-size: auto 560px; }
        #pricing { contain-intrinsic-size: auto 1200px; }
        #signup { contain-intrinsic-size: auto 820px; }
        #faq { contain-intrinsic-size: auto 820px; }

        @media (prefers-reduced-motion: reduce) {
            * { animation: none !important; transition: none !important; }
            html { scroll-behavior: auto; }
            .btn:hover, .card:hover { transform: none; }
        }
    </style>
</head>
<body>

{{-- ══ NAV ══ --}}
<header class="nav-band">
    <nav class="nav" aria-label="{{ $t('brand') }}">
        <a class="brand" href="#top">
            <img class="mark" src="{{ \App\Support\Brand::markUrl() }}" alt="" width="34" height="40">
            <span>{{ $t('brand') }}</span>
        </a>
        <div class="nav-links">
            <a href="#features">{{ $t('nav_features') }}</a>
            <a href="#how">{{ $t('nav_how') }}</a>
            <a href="#pricing">{{ $t('nav_pricing') }}</a>
            <a href="#faq">{{ $t('nav_faq') }}</a>
        </div>
        <div class="lang" role="group" aria-label="Language">
            <a href="?lang=fa" lang="fa" hreflang="fa" aria-current="{{ $rtl ? 'true' : 'false' }}">{{ $t('nav_lang_fa') }}</a>
            <a href="?lang=en" lang="en" hreflang="en" aria-current="{{ $rtl ? 'false' : 'true' }}">{{ $t('nav_lang_en') }}</a>
        </div>
        <a class="btn btn-ghost" href="{{ \App\Support\BrandDomains::appUrl('/portal/login') }}">{{ $t('nav_login') }}</a>
        <a class="btn btn-primary" href="#signup">{{ $t('nav_cta') }}</a>
    </nav>
</header>

<main>
{{-- ══ HERO ══ --}}
<div class="band hero-band" id="top">
    <div class="wrap hero">
        <div class="hero-copy">
            <span class="eyebrow">{{ $t('tagline') }}</span>
            <h1>{{ $h('hero_title') }}</h1>
            <p class="lead">{{ $t('hero_subtitle') }}</p>
            <div class="hero-actions">
                <a class="btn btn-primary" href="#signup">
                    <svg class="arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                    <span>{{ $t('hero_cta') }}</span>
                </a>
                <a class="btn btn-ghost" href="#demo">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M10 9l5 3-5 3z" fill="currentColor"/></svg>
                    <span>{{ $t('hero_cta_secondary') }}</span>
                </a>
            </div>
            <p class="hero-note">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>
                <span>{{ $t('hero_note') }}</span>
            </p>
        </div>

        <div class="hero-art">
            <div class="media hero-media">
                <img src="{{ $img('hero') }}" alt="{{ $alt('hero') }}" width="720" height="720" fetchpriority="high" decoding="async">
            </div>

            <div class="float float-q" aria-hidden="true">
                <span class="av user"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="12" cy="8" r="3.4"/><path d="M5 20c0-3.6 3.1-6 7-6s7 2.4 7 6"/></svg></span>
                <span>{{ $t('hero_demo_customer') }}</span>
            </div>

            <div class="float float-a" aria-hidden="true">
                <div style="display:flex;align-items:center;gap:10px">
                    <span class="av"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="7" width="16" height="12" rx="3"/><path d="M12 7V4M8.5 12.5v1.5M15.5 12.5v1.5"/></svg></span>
                    <span>{{ $t('hero_demo_bot_intro') }}</span>
                </div>
                <div class="mini-card">
                    <span class="mini-thumb"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3h8l-1 5H9z"/><path d="M9 8h6l1 11a2 2 0 01-2 2h-4a2 2 0 01-2-2z"/></svg></span>
                    <div class="mini-meta">
                        <span class="mini-name">{{ $t('hero_demo_product_name') }}</span>
                        <span class="mini-row">
                            <span class="mini-price">{{ $t('hero_demo_product_price') }}</span>
                            <span class="stock"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg><span>{{ $t('hero_demo_product_stock') }}</span></span>
                        </span>
                    </div>
                </div>
                <span class="mini-cta">{{ $t('hero_demo_add_to_cart') }}</span>
            </div>
        </div>
    </div>
</div>

{{-- ══ WHAT IT IS ══ --}}
<section class="band plain" id="about">
    <div class="wrap split">
        <div class="split-copy">
            <span class="eyebrow">{{ $t('about_eyebrow') }}</span>
            <h2>{{ $h('about_title') }}</h2>
            <p class="lead">{{ $t('about_body1') }}</p>
            <p class="lead">{{ $t('about_body2') }}</p>
        </div>
        <div class="split-art" aria-hidden="true">
            <div class="panel">
                <div class="panel-top">
                    <span class="av"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="7" width="16" height="12" rx="3"/><path d="M12 7V4M8.5 12.5v1.5M15.5 12.5v1.5"/></svg></span>
                    <span class="panel-title">{{ $t('brand') }}</span>
                </div>
                <div class="panel-body">
                    <div class="bubble user">{{ $t('panel_q') }}</div>
                    <div class="cards-row">
                        <div class="pcard">
                            <div class="pcard-img"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 13a8 8 0 0116 0v4a2 2 0 01-2 2h-1v-6h3M4 13v4a2 2 0 002 2h1v-6H4"/></svg></div>
                            <span class="pcard-name">{{ $t('p1') }}</span>
                            <span class="pcard-price">{{ $t('p1p') }}</span>
                        </div>
                        <div class="pcard">
                            <div class="pcard-img"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="7" y="6" width="10" height="12" rx="2.5"/><path d="M9.5 6V3.5h5V6M9.5 18v2.5h5V18"/></svg></div>
                            <span class="pcard-name">{{ $t('p2') }}</span>
                            <span class="pcard-price">{{ $t('p2p') }}</span>
                        </div>
                        <div class="pcard">
                            <div class="pcard-img"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="15" r="3"/><circle cx="16" cy="15" r="3"/><path d="M8 12V7M16 12V7"/></svg></div>
                            <span class="pcard-name">{{ $t('p3') }}</span>
                            <span class="pcard-price">{{ $t('p3p') }}</span>
                        </div>
                    </div>
                    <div class="bubble bot">{{ $t('panel_a') }}</div>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ══ FEATURES ══ --}}
<section class="band tinted" id="features">
    <div class="wrap">
        <div class="sec-head">
            <span class="eyebrow">{{ $t('features_eyebrow') }}</span>
            <h2>{{ $h('features_title') }}</h2>
            <p class="lead">{{ $t('features_subtitle') }}</p>
        </div>
        <div class="grid-3">
            @foreach ([
                1 => '<path d="M4 5.5A2.5 2.5 0 016.5 3H19v16H6.5A2.5 2.5 0 004 21.5z"/><path d="M8 8h7M8 12h5"/>',
                2 => '<path d="M12 7v5l3.5 2"/><circle cx="12" cy="12" r="9"/>',
                3 => '<path d="M5 8h6M5 16h6M8 5v6M8 13v6"/><path d="M15 7h5M15 12h5M15 17h5"/>',
                4 => '<path d="M3 5h2.2l2 11h10l2-8H7"/><circle cx="9.5" cy="19.5" r="1.4"/><circle cx="16.5" cy="19.5" r="1.4"/>',
                5 => '<circle cx="12" cy="8" r="3.4"/><path d="M5 20c0-3.6 3.1-6 7-6s7 2.4 7 6"/>',
                6 => '<path d="M5 19V11M12 19V5M19 19v-6"/>',
            ] as $i => $icon)
                <div class="card">
                    <span class="well"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $icon !!}</svg></span>
                    <h3>{{ $t("feature{$i}_title") }}</h3>
                    <p>{{ $t("feature{$i}_body") }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ══ PROOF ══ --}}
<section class="band plain" id="demo">
    <div class="wrap split rev">
        <div class="split-art">
            <div class="media wide">
                <img src="{{ $img('demo') }}" alt="{{ $alt('demo') }}" width="780" height="585" loading="lazy" decoding="async">
            </div>
        </div>
        <div class="split-copy">
            <span class="eyebrow">{{ $t('demo_title') }}</span>
            <h2>{{ $h('demo_head') }}</h2>
            <p class="lead" style="margin-bottom:8px">{{ $t('demo_sub') }}</p>
            <div class="proof">
                <div class="proof-item">
                    <span class="proof-tag">{{ $t('demo_tab_sales') }}</span>
                    <div class="qa">
                        <div class="bubble user">{{ $t('demo_sales_customer') }}</div>
                        <div class="bubble bot">{{ $t('demo_sales_bot') }}</div>
                    </div>
                </div>
                <div class="proof-item">
                    <span class="proof-tag">{{ $t('demo_tab_compare') }}</span>
                    <div class="qa">
                        <div class="bubble user">{{ $t('demo_compare_customer') }}</div>
                        <div class="bubble bot">{{ $t('demo_compare_bot') }}</div>
                    </div>
                </div>
                <div class="proof-item">
                    <span class="proof-tag">{{ $t('demo_tab_gap') }}</span>
                    <p style="font-size:14.5px;color:var(--muted)">{{ $t('demo_gap_note') }}</p>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ══ WHY ══ --}}
<section class="band tinted" id="why">
    <div class="wrap split">
        <div class="split-copy">
            <span class="eyebrow">{{ $t('why_eyebrow') }}</span>
            <h2>{{ $h('why_title') }}</h2>
            <div class="checks">
                @foreach ([1, 2, 3, 4] as $i)
                    <div class="check">
                        <span class="tick"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg></span>
                        <span>{{ $t("why{$i}") }}</span>
                    </div>
                @endforeach
            </div>
        </div>
        <div class="split-art">
            <div class="media wide">
                <img src="{{ $img('why') }}" alt="{{ $alt('why') }}" width="960" height="720" loading="lazy" decoding="async">
            </div>
        </div>
    </div>
</section>

{{-- ══ HOW ══ --}}
<section class="band plain" id="how">
    <div class="wrap">
        <div class="sec-head">
            <span class="eyebrow">{{ $t('how_eyebrow') }}</span>
            <h2>{{ $h('integrations_title') }}</h2>
            <p class="lead">{{ $t('integrations_subtitle') }}</p>
        </div>
        <div class="rail">
            @foreach ([1, 2, 3] as $i)
                <div class="step">
                    <span class="step-n" aria-hidden="true">{{ $steps[$i - 1] }}</span>
                    <h3>{{ $t("integrations_step{$i}_title") }}</h3>
                    <p>{{ $t("integrations_step{$i}_body") }}</p>
                </div>
            @endforeach
        </div>
        <div class="platforms">
            <span class="chip"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3.5 9h17M3.5 15h17M12 3a15 15 0 000 18A15 15 0 0012 3z"/></svg> <span>{{ $t('integrations_wp') }}</span></span>
            <span class="chip"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h2l2 10h11l2-7H7"/><circle cx="9.5" cy="19.5" r="1.4"/><circle cx="16.5" cy="19.5" r="1.4"/></svg> <span>{{ $t('integrations_woo') }}</span></span>
        </div>
    </div>
</section>

{{-- ══ PRICING — read from the plans an admin published ══ --}}
<section class="band tinted" id="pricing">
    <div class="wrap">
        <div class="sec-head">
            <span class="eyebrow">{{ $t('pricing_eyebrow') }}</span>
            <h2>{{ $t('pricing_title') }}</h2>
            <p class="lead">{{ $t('pricing_subtitle') }}</p>
        </div>

        @if ($plans->isEmpty())
            <p class="notice">{{ $t('pricing_empty') }}</p>
        @elseif ($pricingComingSoon)
            <p class="notice">{{ $t('pricing_coming_soon') }}</p>
        @else
            <div class="price-grid">
                @foreach ($visiblePlans as $plan)
                    @php $isPopular = $popularSlug !== '' && $plan->slug === $popularSlug; @endphp
                    <div class="price {{ $isPopular ? 'popular' : '' }}">
                        @if ($isPopular)
                            <span class="popular-badge">{{ $t('pricing_popular') }}</span>
                        @endif
                        <h3>{{ $plan->display_name }}</h3>
                        @php $amount = \App\Support\Money::convert($plan->price_monthly, $currency); @endphp
                        <div class="amount">
                            {{ number_format((float) $amount) }}
                            <span class="unit">{{ \App\Support\Money::unitLabel($currency) }}</span>
                        </div>
                        <div class="muted" style="font-size:14px">{{ $t('pricing_per_month') }}</div>

                        @if ($plan->display_description)
                            <p class="muted" style="margin-top:12px;font-size:14.5px">{{ $plan->display_description }}</p>
                        @endif

                        <ul>
                            <li>{{ trans_choice('landing.pricing_chatbots', (int) $plan->max_chatbots, ['count' => number_format((int) $plan->max_chatbots)]) }}</li>
                            <li>{{ trans_choice('landing.pricing_tokens', (int) $plan->max_tokens_monthly, ['count' => number_format((int) $plan->max_tokens_monthly)]) }}</li>
                            @if ($plan->max_documents)
                                <li>{{ trans_choice('landing.pricing_documents', (int) $plan->max_documents, ['count' => number_format((int) $plan->max_documents)]) }}</li>
                            @endif
                            {{-- No domain count: one chatbot = one domain, so "3 domains" would
                                 promise a feature that does not exist. A plan's reach is its
                                 number of chatbots. --}}
                            @foreach ($plan->display_features as $feature)
                                <li>{{ $feature }}</li>
                            @endforeach
                        </ul>

                        <a class="btn {{ $isPopular ? 'btn-primary' : 'btn-ghost' }}" href="#signup">{{ $t('pricing_cta') }}</a>
                    </div>
                @endforeach
            </div>

            <div class="pricing-notes">
                @if ($freePlan ?? null)
                    <p>{{ $t('pricing_free_line', [
                        'chatbots'  => trans_choice('landing.pricing_chatbots', (int) $freePlan->max_chatbots, ['count' => number_format((int) $freePlan->max_chatbots)]),
                        'documents' => trans_choice('landing.pricing_documents', (int) $freePlan->max_documents, ['count' => number_format((int) $freePlan->max_documents)]),
                        'messages'  => number_format((int) $freePlan->max_messages_monthly),
                    ]) }}</p>
                @endif
                <p>{{ $t('pricing_enterprise_note') }}</p>
            </div>

            {{-- Built only from plans.allowed_tools — see LandingController
                 ::toolComparisonRows(). No hardcoded feature array here. --}}
            @if (!empty($toolRows))
                <h3 class="sub-title">{{ $t('tool_compare_title') }}</h3>

                <div class="tool-compare-wrap">
                    <table class="tool-compare">
                        <thead>
                            <tr>
                                <th>{{ $t('tool_compare_feature') }}</th>
                                @foreach ($visiblePlans as $plan)
                                    <th>{{ $plan->display_name }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($toolRows as $toolName)
                                <tr>
                                    <th scope="row">{{ \App\Support\ChatbotTools::label($toolName) }}</th>
                                    @foreach ($visiblePlans as $plan)
                                        <td>
                                            @if (in_array($toolName, $plan->allowed_tools ?? [], true))
                                                <span class="tool-yes" aria-label="{{ $t('tool_compare_yes') }}">&#10003;</span>
                                            @else
                                                <span class="tool-no" aria-label="{{ $t('tool_compare_no') }}">&mdash;</span>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="tool-compare-cards">
                    @foreach ($toolRows as $toolName)
                        <div class="tool-compare-card">
                            <div class="name">{{ \App\Support\ChatbotTools::label($toolName) }}</div>
                            @foreach ($visiblePlans as $plan)
                                <div class="row">
                                    <span>{{ $plan->display_name }}</span>
                                    @if (in_array($toolName, $plan->allowed_tools ?? [], true))
                                        <span class="tool-yes" aria-label="{{ $t('tool_compare_yes') }}">&#10003;</span>
                                    @else
                                        <span class="tool-no" aria-label="{{ $t('tool_compare_no') }}">&mdash;</span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            @endif
        @endif

        @if ($chatbotTypes->isNotEmpty())
            <div class="types">
                <h3>{{ $t('types_title') }}</h3>
                <p class="muted" style="font-size:14.5px;margin-top:6px">{{ $t('types_subtitle') }}</p>
                <ul class="type-list">
                    @foreach ($chatbotTypes as $type)
                        @php $typeAmount = \App\Support\Money::convert($type->price_toman, $currency); @endphp
                        @if ($typeAmount !== null)
                        <li>
                            <span>{{ $type->display_name }}</span>
                            <strong>{{ number_format($typeAmount) }}
                                <span class="muted" style="font-weight:500;font-size:13.5px">{{ \App\Support\Money::unitLabel($currency) }}</span>
                            </strong>
                        </li>
                        @endif
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
</section>

{{-- ══ SIGNUP ══ --}}
<section class="band plain" id="signup">
    <div class="wrap signup">
        <div class="sec-head" style="margin-bottom:28px">
            <h2>{{ $t('signup_title') }}</h2>
            <p class="lead">{{ $t('signup_subtitle') }}</p>
        </div>

        @if (session('landing_status'))
            <p class="notice ok" style="margin-bottom:18px">{{ session('landing_status') }}</p>
        @endif

        @if (! $emailSignup)
            <p class="notice">{{ $t('signup_email_disabled') }}</p>
            <p style="text-align:center;margin-top:20px"><a class="btn btn-primary" href="{{ \App\Support\BrandDomains::appUrl('/portal/login?method=phone') }}">{{ $t('signup_phone_alternative') }}</a></p>
        @else
            <div class="form-card">
                <form method="POST" action="{{ route('landing.register') }}">
                    @csrf
                    <input type="hidden" name="form_rendered_at" value="{{ now()->timestamp }}">
                    {{-- Honeypot: invisible to a human, a plain text field a bot that fills every input trips. Never required, never shown in an error. Name deliberately NOT "website"/"website_url" or anything else autofill/password-manager heuristics recognise — see App\Support\SignupRisk::HONEYPOT_FIELD's own docblock. A filled value here now gets the signup refused outright, not just flagged. --}}
                    <div style="position:absolute;left:-9999px;top:-9999px" aria-hidden="true">
                        <label for="company_fax">Company Fax</label>
                        <input type="text" id="company_fax" name="company_fax" tabindex="-1" autocomplete="off">
                    </div>
                    <label for="name">{{ $t('signup_name') }}</label>
                    <input id="name" name="name" value="{{ old('name') }}" required maxlength="255" autocomplete="name">
                    @error('name') <div class="field-error">{{ $message }}</div> @enderror

                    <label for="email">{{ $t('signup_email') }}</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" required maxlength="255" autocomplete="email" dir="ltr">
                    @error('email') <div class="field-error">{{ $message }}</div> @enderror

                    <label for="country">{{ $t('signup_country') }}</label>
                    <select id="country" name="country" required>
                        <option value="" disabled {{ old('country') ? '' : 'selected' }}>{{ $t('signup_country_placeholder') }}</option>
                        @foreach ($countries as $code => $label)
                            <option value="{{ $code }}" {{ old('country') === $code ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                    <p class="field-help">{{ $t('signup_country_help') }}</p>
                    @error('country') <div class="field-error">{{ $message }}</div> @enderror

                    <label for="password">{{ $t('signup_password') }}</label>
                    <input id="password" name="password" type="password" required minlength="8" autocomplete="new-password">
                    @error('password') <div class="field-error">{{ $message }}</div> @enderror

                    <label for="password_confirmation">{{ $t('signup_password_confirm') }}</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" required minlength="8" autocomplete="new-password">

                    <button class="btn btn-primary btn-block" type="submit">{{ $t('signup_submit') }}</button>
                </form>
            </div>

            <p class="signup-alt">
                <a href="{{ \App\Support\BrandDomains::appUrl('/portal/login?method=phone') }}">{{ $t('signup_phone_alternative') }}</a>
                &nbsp;·&nbsp;
                <a href="{{ \App\Support\BrandDomains::appUrl('/portal/login') }}">{{ $t('signup_have_account') }}</a>
            </p>
        @endif
    </div>
</section>

{{-- ══ FAQ — LandingController::faq(), editable as a list in the panel ══ --}}
<section class="band tinted" id="faq">
    <div class="wrap">
        <div class="sec-head">
            <span class="eyebrow">{{ $t('faq_title') }}</span>
            <h2>{{ $h('faq_head') }}</h2>
        </div>
        <div class="faq">
            @foreach ($faq as $item)
                <details @if ($loop->first) open @endif>
                    <summary>{{ $item['q'] }}<span class="caret" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg></span></summary>
                    <p>{{ $item['a'] }}</p>
                </details>
            @endforeach
        </div>
    </div>
</section>

{{-- ══ CTA ══ --}}
<section class="band cta-band" aria-labelledby="final-cta">
    <div class="wrap tight cta">
        <div>
            <h2 id="final-cta">{{ $h('final_cta_title') }}</h2>
            <p>{{ $t('final_cta_subtitle') }}</p>
        </div>
        <div class="cta-actions">
            <a class="btn btn-light" href="#signup">
                <svg class="arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                <span>{{ $t('final_cta_button') }}</span>
            </a>
            <a class="btn btn-outline-light" href="#pricing">{{ $t('cta_plans') }}</a>
        </div>
    </div>
</section>
</main>

{{-- ══ FOOTER ══ --}}
<footer class="band foot-band">
    <div class="wrap foot">
        <div style="display:flex;flex-direction:column;gap:7px">
            <span class="brand">
                <img class="mark" src="{{ \App\Support\Brand::markLightUrl() }}" alt="" width="34" height="40">
                <span>{{ $t('brand') }}</span>
            </span>
            <span>{{ $t('footer_tagline') }}</span>
        </div>
        <nav class="foot-links" aria-label="{{ $t('brand') }}">
            <a href="#features">{{ $t('nav_features') }}</a>
            <a href="#pricing">{{ $t('nav_pricing') }}</a>
            <a href="#faq">{{ $t('nav_faq') }}</a>
            <a href="{{ \App\Support\BrandDomains::appUrl('/portal/login') }}">{{ $t('footer_login') }}</a>
            <a href="{{ route('legal', 'about') }}">{{ __('legal.about_title') }}</a>
            <a href="{{ route('legal', 'contact') }}">{{ __('legal.contact_title') }}</a>
            <a href="{{ route('legal', 'terms') }}">{{ __('legal.terms_title') }}</a>
            <a href="{{ route('legal', 'privacy') }}">{{ __('legal.privacy_title') }}</a>
        </nav>
    </div>
    <div class="wrap foot-meta" style="padding-top:18px">
        {{-- Set once from the admin panel's Site Content page (Contact info),
             read from the same place the /legal/contact page reads it —
             never hardcoded twice. Each piece only renders once set. --}}
        @if ($contact['address'] || $contact['phone'] || $contact['email'])
            <span class="foot-contact">
                @if ($contact['address'])<span>{{ $contact['address'] }}</span>@endif
                @if ($contact['phone'])<span dir="ltr">{{ $contact['phone'] }}</span>@endif
                @if ($contact['email'])<span dir="ltr">{{ $contact['email'] }}</span>@endif
            </span>
        @endif
        <span>© {{ date('Y') }} {{ $t('brand') }} — {{ $t('footer_rights') }}</span>
    </div>
</footer>

</body>
</html>
