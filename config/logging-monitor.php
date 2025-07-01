<?php

// Monitor uses Laravel's default logging channel (Log::info(), Log::error(), etc.)
// so it works with whatever you have configured as your default channel.
//
// If you want Monitor logs to go to a separate channel, you can:
// 1. Set a different default channel in your logging.php config, OR
// 2. Create a dedicated channel and manually use it like Log::channel('monitor')->info()
//
// The example below shows how you could configure a dedicated channel with Monitor's
// structured logging tap, but this is completely optional.

return [
    'channels' => [
        'monitor_example' => [
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
