<?php
return [
    'ai_service' => [
        'url'    => env('AI_SERVICE_URL', 'http://python_ai:8001'),
        'secret' => env('AI_SERVICE_SECRET'),
        'timeout'=> 30,
        'retry'  => 2,
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
];
