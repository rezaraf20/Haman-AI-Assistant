<?php
return [
    'ai_service' => [
        'url'    => env('AI_SERVICE_URL', 'http://python_ai:8001'),
        'secret' => env('AI_SERVICE_SECRET'),
        'timeout'=> 30,
        'retry'  => 2,
        // A streamed reply's total wall time (not just time-to-first-byte)
        // can genuinely exceed the normal 30s request timeout even though
        // bytes are arriving the whole time — AiGatewayService::chatStream()
        // uses this instead.
        'stream_timeout' => env('AI_SERVICE_STREAM_TIMEOUT', 120),
    ],
    'tenant' => [
        'api_key_prefix' => 'hfp_',
        'api_key_length' => 32,
        'api_key_cache'  => 300,
        'trial_days'     => 14,
        'schema_prefix'  => 'tenant_',
    ],
    'rag' => [
        'default_top_k'     => 8,
        'default_threshold' => 0.60,
        'memory_window'     => 6,
    ],
    // The hostnames the platform answers on. One application, four names:
    //
    //   landing  hamanai.com       the public site and signup
    //   app      app.hamanai.com   the customer and admin panels
    //   api      api.hamanai.com   the API and the chat widget
    //   api_public                 the API address handed to customers
    //
    // 'api_public' is deliberately separate from 'api'. Every plugin already
    // installed on a customer's site has api.arshanweb.ir baked into its
    // options, that address stays live permanently, and new installs keep
    // pointing at it until api.hamanai.com has proven itself over months of
    // traffic. When that day comes this one value moves and nothing else does.
    //
    // 'session' is the cookie domain shared between the hamanai.com hosts, so
    // that signing up on the landing page arrives at the panel already logged
    // in. It is applied per request rather than through SESSION_DOMAIN,
    // because a single static value would scope the cookie to .hamanai.com on
    // every host -- including api.arshanweb.ir, whose browser would then drop
    // the cookie and break every session-backed page it serves. See
    // ShareSessionAcrossBrandDomains.
    'domains' => [
        'landing'    => env('HAMAN_DOMAIN_LANDING', 'hamanai.com'),
        'app'        => env('HAMAN_DOMAIN_APP', 'app.hamanai.com'),
        'api'        => env('HAMAN_DOMAIN_API', 'api.hamanai.com'),
        'api_public' => env('HAMAN_API_PUBLIC_URL', 'https://api.arshanweb.ir'),
        'session'    => env('HAMAN_SESSION_DOMAIN', '.hamanai.com'),
    ],

    'zarinpal' => [
        'merchant_id' => env('ZARINPAL_MERCHANT_ID'),
        'sandbox'     => env('ZARINPAL_SANDBOX', true),
    ],
    // Single source of truth for the brand identity — previously '#1B3A6B'
    // was hardcoded separately in AdminPanelProvider, CustomerPanelProvider,
    // and WidgetDefaults::common() (the chat widget's own default). One
    // name, one color, read from here everywhere.
    //
    // "Haman AI" and the hamanai.com domain replaced "HamanTech"/
    // hamantech.ir once a real logo and palette existed to design around —
    // see resources/brand/ for the source file and public/css/brand.css for
    // the full --brand-* token set this single color is one entry from.
    // primary_color is the mark's brightest gradient stop (#0098F8), used
    // as-is rather than the gradient itself: Filament's colors() and the
    // chat widget's primary_color both expect one hex value they can derive
    // a shade scale from, not a gradient.
    'brand' => [
        'name'          => 'Haman AI',
        'url'           => 'https://hamanai.com',
        'primary_color' => '#0098F8',
    ],
    // What the WordPress plugin's Advanced tab compares its own HAMAN_VERSION
    // against to show an "update available" notice — bump this by hand
    // (env var, no dedicated UI yet) whenever a new plugin zip is actually
    // published for customers to install.
    //
    // This used to say it could not silently drift from reality, on the
    // grounds that a manual value is a deliberate one. It drifted anyway:
    // the plugin header reached 1.9.0 while this stayed at 1.8.0, so nobody
    // running 1.8.0 was ever told an update existed. preflight now compares
    // the two and fails when they disagree, which is what actually keeps
    // them together.
    'wp_plugin' => [
        'latest_version' => env('HAMAN_WP_PLUGIN_LATEST_VERSION', '2.1.1'),
    ],
];
