@php
    $rtl = app()->getLocale() === 'fa';
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('landing.brand') }} — {{ __('landing.hero_title') }}</title>
    <meta name="description" content="{{ __('landing.hero_subtitle') }}">
    <link rel="canonical" href="{{ url('/') }}">

    {{-- What a shared link looks like in a chat app or a search result.
         og:locale follows the rendered language, since the same URL serves
         both. No image is declared: a broken or placeholder og:image looks
         worse than none, and there is no brand asset for it yet.
         TODO(business): add og:image once there is artwork to point at. --}}
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ __('landing.brand') }}">
    <meta property="og:title" content="{{ __('landing.brand') }} — {{ __('landing.hero_title') }}">
    <meta property="og:description" content="{{ __('landing.hero_subtitle') }}">
    <meta property="og:url" content="{{ url('/') }}">
    <meta property="og:locale" content="{{ $rtl ? 'fa_IR' : 'en_US' }}">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="{{ __('landing.brand') }} — {{ __('landing.hero_title') }}">
    <meta name="twitter:description" content="{{ __('landing.hero_subtitle') }}">
    <style>
        :root {
            --ink: #14181f; --muted: #5d6673; --line: #e4e7ec;
            --bg: #ffffff; --soft: #f7f8fa; --brand: #1f6feb; --ok: #16794c; --warn: #b45309;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; background: var(--bg); color: var(--ink);
            font-family: {{ $rtl ? "'Vazirmatn', Tahoma, system-ui" : "system-ui, -apple-system, Segoe UI, sans-serif" }};
            line-height: 1.75; font-size: 16px;
        }
        a { color: var(--brand); text-decoration: none; }
        .wrap { max-width: 1080px; margin: 0 auto; padding: 0 20px; }
        h1, h2, h3 { line-height: 1.3; margin: 0 0 .5em; }
        h1 { font-size: clamp(1.9rem, 4vw, 3rem); letter-spacing: -.02em; }
        h2 { font-size: clamp(1.4rem, 2.6vw, 2rem); letter-spacing: -.01em; }
        p { margin: 0 0 1em; }
        .muted { color: var(--muted); }

        header { border-bottom: 1px solid var(--line); position: sticky; top: 0; background: rgba(255,255,255,.92); backdrop-filter: blur(8px); z-index: 10; }
        header .wrap { display: flex; align-items: center; gap: 24px; height: 64px; }
        .brand { font-weight: 700; font-size: 1.15rem; }
        nav { display: flex; gap: 20px; margin-inline-start: auto; align-items: center; flex-wrap: wrap; }
        nav a { color: var(--muted); font-size: .95rem; }
        nav a:hover { color: var(--ink); }

        .type-list { list-style: none; margin: 14px 0 0; padding: 0; max-width: 520px; }
        .type-list li { display: flex; justify-content: space-between; gap: 16px; padding: 12px 0; border-bottom: 1px solid var(--line); }
        .type-list li:last-child { border-bottom: 0; }

        .btn { display: inline-block; padding: 11px 20px; border-radius: 8px; font-weight: 600; font-size: .95rem; border: 1px solid transparent; cursor: pointer; }
        .btn-primary { background: var(--brand); color: #fff; }
        .btn-ghost { border-color: var(--line); color: var(--ink); background: #fff; }

        section { padding: 72px 0; border-bottom: 1px solid var(--line); }
        .lead { font-size: 1.15rem; color: var(--muted); max-width: 62ch; }
        .grid { display: grid; gap: 24px; }
        @media (min-width: 760px) { .grid-3 { grid-template-columns: repeat(3, 1fr); } .grid-2 { grid-template-columns: repeat(2, 1fr); } }

        .card { border: 1px solid var(--line); border-radius: 12px; padding: 22px; background: #fff; }
        .card h3 { font-size: 1.1rem; }
        .card p { margin: 0; color: var(--muted); font-size: .96rem; }

        .soft { background: var(--soft); }

        /* Report mock */
        .mock { border: 1px solid var(--line); border-radius: 12px; overflow: hidden; background: #fff; }
        .mock-bar { background: var(--soft); border-bottom: 1px solid var(--line); padding: 10px 16px; font-size: .85rem; color: var(--muted); display: flex; justify-content: space-between; align-items: center; }
        .tag { font-size: .72rem; background: #eef2f7; color: var(--muted); padding: 2px 8px; border-radius: 99px; }
        table { width: 100%; border-collapse: collapse; font-size: .9rem; }
        th, td { text-align: {{ $rtl ? 'right' : 'left' }}; padding: 9px 16px; border-bottom: 1px solid var(--line); }
        th { color: var(--muted); font-weight: 600; font-size: .8rem; }
        tr:last-child td { border-bottom: 0; }
        .up { color: var(--ok); font-weight: 600; }
        .down { color: var(--warn); font-weight: 600; }
        .barcell { display: flex; align-items: center; gap: 8px; }
        .bar { height: 7px; border-radius: 4px; background: var(--brand); opacity: .75; }

        .price-grid { display: grid; gap: 20px; }
        @media (min-width: 760px) { .price-grid { grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); } }
        .price { border: 1px solid var(--line); border-radius: 12px; padding: 26px; display: flex; flex-direction: column; }
        .price .amount { font-size: 2.1rem; font-weight: 700; letter-spacing: -.02em; }
        .price ul { list-style: none; padding: 0; margin: 16px 0 24px; }
        .price li { padding: 6px 0; color: var(--muted); font-size: .93rem; border-bottom: 1px dashed var(--line); }
        .price li:last-child { border-bottom: 0; }
        .price .btn { margin-top: auto; text-align: center; }

        .notice { border: 1px solid var(--line); border-inline-start: 3px solid var(--warn); background: #fffbf5; padding: 14px 16px; border-radius: 8px; color: var(--muted); font-size: .93rem; }

        form label { display: block; font-size: .88rem; font-weight: 600; margin-bottom: 5px; }
        form input, form textarea { width: 100%; padding: 10px 12px; border: 1px solid var(--line); border-radius: 8px; font: inherit; margin-bottom: 14px; background: #fff; }
        .field-error { color: #b42318; font-size: .85rem; margin: -10px 0 12px; }

        details { border-bottom: 1px solid var(--line); padding: 16px 0; }
        details summary { cursor: pointer; font-weight: 600; list-style: none; }
        details summary::-webkit-details-marker { display: none; }
        details p { margin: 12px 0 0; color: var(--muted); }

        footer { padding: 40px 0; color: var(--muted); font-size: .9rem; }
        footer .wrap { display: flex; gap: 20px; flex-wrap: wrap; align-items: center; }
    </style>
</head>
<body>

<header>
    <div class="wrap">
        <span class="brand">{{ __('landing.brand') }}</span>
        <nav>
            <a href="#features">{{ __('landing.nav_features') }}</a>
            <a href="#reports">{{ __('landing.nav_reports') }}</a>
            <a href="#pricing">{{ __('landing.nav_pricing') }}</a>
            <a href="#faq">{{ __('landing.nav_faq') }}</a>
            <a href="{{ url('/portal/login') }}">{{ __('landing.nav_login') }}</a>
            <a class="btn btn-primary" href="#signup">{{ __('landing.nav_cta') }}</a>
        </nav>
    </div>
</header>

{{-- Hero --}}
<section>
    <div class="wrap">
        <h1>{{ __('landing.hero_title') }}</h1>
        <p class="lead">{{ __('landing.hero_subtitle') }}</p>
        <p style="margin-top:28px">
            <a class="btn btn-primary" href="#signup">{{ __('landing.hero_cta') }}</a>
            <a class="btn btn-ghost" href="#signup">{{ __('landing.hero_cta_secondary') }}</a>
        </p>
        <p class="muted" style="font-size:.9rem">{{ __('landing.hero_note') }}</p>
    </div>
</section>

{{-- The three things --}}
<section id="features" class="soft">
    <div class="wrap">
        <h2>{{ __('landing.features_title') }}</h2>
        <p class="lead">{{ __('landing.features_subtitle') }}</p>
        <div class="grid grid-3" style="margin-top:32px">
            @foreach ([1, 2, 3] as $i)
                <div class="card">
                    <h3>{{ __("landing.feature{$i}_title") }}</h3>
                    <p>{{ __("landing.feature{$i}_body") }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- Reports: the part that sells --}}
<section id="reports">
    <div class="wrap">
        <h2>{{ __('landing.reports_title') }}</h2>
        <p class="lead">{{ __('landing.reports_subtitle') }}</p>

        <div class="grid grid-2" style="margin-top:32px; align-items:start">
            {{-- Trends --}}
            <div class="mock">
                <div class="mock-bar">
                    <span>{{ __('landing.report_trends_title') }}</span>
                    <span class="tag">{{ __('landing.report_demo_label') }}</span>
                </div>
                <table>
                    <thead><tr><th>{{ __('landing.nav_features') }}</th><th>30d</th><th></th></tr></thead>
                    <tbody>
                        @foreach ([['price', 82, '+', 100], ['availability', 64, '+', 78], ['comparison', 41, '-', 50], ['shipping', 23, '+', 28]] as [$topic, $n, $dir, $w])
                            <tr>
                                <td>{{ $topic }}</td>
                                <td>
                                    <span class="barcell">
                                        <span class="bar" style="width:{{ $w }}px"></span>{{ $n }}
                                    </span>
                                </td>
                                <td class="{{ $dir === '+' ? 'up' : 'down' }}">{{ $dir }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Demand gap --}}
            <div class="mock">
                <div class="mock-bar">
                    <span>{{ __('landing.report_gap_title') }}</span>
                    <span class="tag">{{ __('landing.report_demo_label') }}</span>
                </div>
                <table>
                    <thead><tr><th>—</th><th>—</th></tr></thead>
                    <tbody>
                        @foreach ([['A', 14], ['B', 11], ['C', 9], ['D', 6]] as [$item, $n])
                            <tr><td>{{ $item }}</td><td>{{ $n }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="grid grid-2" style="margin-top:24px">
            @foreach (['trends', 'gap', 'compared', 'unanswered'] as $r)
                <div class="card">
                    <h3>{{ __("landing.report_{$r}_title") }}</h3>
                    <p>{{ __("landing.report_{$r}_body") }}</p>
                </div>
            @endforeach
        </div>

        <p class="muted" style="margin-top:20px; font-size:.87rem">{{ __('landing.report_demo_note') }}</p>
    </div>
</section>

{{-- Pricing, read from the plans an admin published --}}
<section id="pricing" class="soft">
    <div class="wrap">
        <h2>{{ __('landing.pricing_title') }}</h2>
        <p class="lead">{{ __('landing.pricing_subtitle') }}</p>

        @if ($plans->isEmpty())
            <p class="notice" style="margin-top:28px">{{ __('landing.pricing_empty') }}</p>
        @else
            <div class="price-grid" style="margin-top:32px">
                @foreach ($plans as $plan)
                    <div class="price">
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

                        <a class="btn btn-primary" href="#signup">{{ __('landing.pricing_cta') }}</a>
                    </div>
                @endforeach
            </div>

            <p class="muted" style="margin-top:20px;font-size:.9rem">{{ __('landing.pricing_enterprise_note') }}</p>
        @endif

        {{-- What a chatbot itself costs, read from the same table the panel
             charges from. A plan is the monthly subscription; this is the
             one-off per chatbot, and someone comparing the site against a
             quote needs both or the page looks like it is hiding one.
             Outside the plans branch above on purpose: a shop with no
             published plan still has a price per chatbot. --}}
        @if ($chatbotTypes->isNotEmpty())
            <div class="types" style="margin-top:40px">
                <h3 style="font-size:1.1rem">{{ __('landing.types_title') }}</h3>
                <p class="muted" style="font-size:.92rem">{{ __('landing.types_subtitle') }}</p>
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

{{-- Signup / demo --}}
<section id="signup">
    <div class="wrap" style="max-width:560px">
        <h2>{{ __('landing.signup_title') }}</h2>
        <p class="lead">{{ __('landing.signup_subtitle') }}</p>

        @if (session('landing_status'))
            <p class="notice" style="border-inline-start-color: var(--ok); background:#f4fbf7">{{ session('landing_status') }}</p>
        @endif

        @if (! $emailSignup)
            {{-- Email signup depends on SMTP being configured, because the
                 account has to be verifiable. Shown as unavailable with the
                 reason, rather than failing after the form is submitted. --}}
            <p class="notice">{{ __('landing.signup_email_disabled') }}</p>
            <p><a class="btn btn-primary" href="{{ url('/portal/login?method=phone') }}">{{ __('landing.signup_phone_alternative') }}</a></p>
        @else
            <form method="POST" action="{{ route('landing.register') }}" style="margin-top:24px">
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

                <button class="btn btn-primary" type="submit" style="width:100%">{{ __('landing.signup_submit') }}</button>
            </form>

            <p class="muted" style="font-size:.9rem;margin-top:16px">
                <a href="{{ url('/portal/login?method=phone') }}">{{ __('landing.signup_phone_alternative') }}</a>
                &nbsp;·&nbsp;
                <a href="{{ url('/portal/login') }}">{{ __('landing.signup_have_account') }}</a>
            </p>
        @endif
    </div>
</section>

{{-- FAQ --}}
<section id="faq" class="soft">
    <div class="wrap" style="max-width:760px">
        <h2>{{ __('landing.faq_title') }}</h2>
        @foreach ($faq as $item)
            <details>
                <summary>{{ $item['q'] }}</summary>
                <p>{{ $item['a'] }}</p>
            </details>
        @endforeach
    </div>
</section>

<footer>
    <div class="wrap">
        <strong>{{ __('landing.brand') }}</strong>
        <span class="muted">{{ __('landing.footer_tagline') }}</span>
        <span style="margin-inline-start:auto">
            <a href="{{ url('/portal/login') }}">{{ __('landing.footer_login') }}</a>
        </span>
        <span class="muted">© {{ date('Y') }} — {{ __('landing.footer_rights') }}</span>
    </div>
</footer>

</body>
</html>
