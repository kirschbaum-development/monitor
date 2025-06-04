<?php

return [
    'channels' => [
        'monitor' => [
            'driver' => 'daily',
            'path' => storage_path('logs/monitor.log'),
            'level' => 'debug',
            'days' => 14,
            'tap' => [
                \Kirschbaum\Monitor\Taps\StructuredLoggingTap::class,
            ],
        ],
    ],
];
