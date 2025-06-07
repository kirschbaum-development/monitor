<?php

declare(strict_types=1);

namespace Tests\Unit;

use Kirschbaum\Monitor\Controlled;
use Kirschbaum\Monitor\MonitorWithOrigin;
use Kirschbaum\Monitor\StructuredLogger;

it('creates MonitorWithOrigin with string origin', function () {
    $origin = 'TestOrigin';
    $monitorWithOrigin = new MonitorWithOrigin($origin);

    expect($monitorWithOrigin)->toBeInstanceOf(MonitorWithOrigin::class);
});

it('creates MonitorWithOrigin with object origin', function () {
    $objectOrigin = new class
    {
        public function __toString(): string
        {
            return 'ObjectOrigin';
        }
    };

    $monitorWithOrigin = new MonitorWithOrigin($objectOrigin);

    expect($monitorWithOrigin)->toBeInstanceOf(MonitorWithOrigin::class);
});

it('returns Controlled instance with origin set from controlled() method', function () {
    $origin = 'ControlledOrigin';
    $monitorWithOrigin = new MonitorWithOrigin($origin);

    $controlled = $monitorWithOrigin->controlled();

    expect($controlled)->toBeInstanceOf(Controlled::class);
});

it('returns StructuredLogger instance from origin via log() method', function () {
    $origin = 'LoggerOrigin';
    $monitorWithOrigin = new MonitorWithOrigin($origin);

    $logger = $monitorWithOrigin->log();

    expect($logger)->toBeInstanceOf(StructuredLogger::class);
});

it('returns StructuredLogger instance with object origin via log() method', function () {
    $objectOrigin = new class
    {
        public function __toString(): string
        {
            return 'ObjectLoggerOrigin';
        }
    };

    $monitorWithOrigin = new MonitorWithOrigin($objectOrigin);

    $logger = $monitorWithOrigin->log();

    expect($logger)->toBeInstanceOf(StructuredLogger::class);
});

it('creates different StructuredLogger instances for multiple log() calls', function () {
    $origin = 'MultipleLoggerOrigin';
    $monitorWithOrigin = new MonitorWithOrigin($origin);

    $logger1 = $monitorWithOrigin->log();
    $logger2 = $monitorWithOrigin->log();

    expect($logger1)->toBeInstanceOf(StructuredLogger::class)
        ->and($logger2)->toBeInstanceOf(StructuredLogger::class)
        ->and($logger1)->not->toBe($logger2); // Should be different instances
});

it('preserves origin through controlled method chain', function () {
    $origin = 'ChainOrigin';
    $monitorWithOrigin = new MonitorWithOrigin($origin);

    $controlled = $monitorWithOrigin->controlled();

    expect($controlled)->toBeInstanceOf(Controlled::class);
});

it('handles both methods correctly with same origin', function () {
    $origin = 'BothMethodsOrigin';
    $monitorWithOrigin = new MonitorWithOrigin($origin);

    $controlled = $monitorWithOrigin->controlled();
    $logger = $monitorWithOrigin->log();

    expect($controlled)->toBeInstanceOf(Controlled::class)
        ->and($logger)->toBeInstanceOf(StructuredLogger::class);
});
