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
    'zarinpal' => [
        'merchant_id' => env('ZARINPAL_MERCHANT_ID'),
        'sandbox'     => env('ZARINPAL_SANDBOX', true),
    ],
    // Single source of truth for the brand identity — previously '#1B3A6B'
    // was hardcoded separately in AdminPanelProvider, CustomerPanelProvider,
    // and WidgetDefaults::common() (the chat widget's own default), and the
    // admin panel's brandName() said "Haman AI" while the actual product/
    // domain/chat-widget branding is "HamanTech" / hamantech.ir — one name,
    // one color, read from here everywhere.
    'brand' => [
        'name'          => 'HamanTech',
        'url'           => 'https://hamantech.ir',
        'primary_color' => '#1B3A6B',
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
        'latest_version' => env('HAMAN_WP_PLUGIN_LATEST_VERSION', '2.0.0'),
    ],
];
