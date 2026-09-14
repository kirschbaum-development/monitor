<?php

declare(strict_types=1);

use Tests\Fixtures\Flaky;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit', 'Performance');

/**
 * Whether a coverage driver is actively instrumenting this run. Timing
 * assertions skip themselves under instrumentation rather than flake.
 */
function runningWithCoverage(): bool
{
    if (extension_loaded('pcov') && (bool) ini_get('pcov.enabled')) {
        return true;
    }

    return extension_loaded('xdebug')
        && str_contains((string) ini_get('xdebug.mode'), 'coverage');
}

/**
 * A callback that throws the given exception the first $failures times it is called.
 */
function failingTimes(int $failures, ?Throwable $with = null): Closure
{
    $calls = 0;
    $with ??= new Flaky('flaky');

    return function () use (&$calls, $failures, $with): string {
        $calls++;

        if ($calls <= $failures) {
            throw $with;
        }

        return "ok after {$calls}";
    };
}
