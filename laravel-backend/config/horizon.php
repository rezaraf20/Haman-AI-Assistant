<?php
return [
    'domain'  => null,
    'path'    => 'horizon',
    'prefix'  => env('HORIZON_PREFIX','hamman_horizon'),
    'middleware'=> ['web'],
    'waits'   => ['redis' => 60],
    'trim'    => ['recent'=>60,'pending'=>60,'completed'=>60,'recent_failed'=>10080,'failed'=>10080,'monitored'=>10080],
    'environments' => [
        'production' => [
            'supervisor-embeddings' => [
                'connection' => 'redis', 'queue' => ['embeddings'],
                'balance' => 'simple', 'processes' => 3, 'timeout' => 120,
            ],
            'supervisor-sync' => [
                'connection' => 'redis', 'queue' => ['sync','webhooks'],
                'balance' => 'simple', 'processes' => 2, 'timeout' => 300,
            ],
            'supervisor-default' => [
                'connection' => 'redis', 'queue' => ['default','notifications','analytics'],
                'balance' => 'simple', 'processes' => 2, 'timeout' => 60,
            ],
        ],
    ],
];
