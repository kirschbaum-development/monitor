<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Facades;

use Illuminate\Support\Facades\Facade;
use Kirschbaum\Monitor\Contracts\Runner;
use Kirschbaum\Monitor\Testing\MonitorFake;
use RuntimeException;

/**
 * @method static \Kirschbaum\Monitor\Control control(string|\BackedEnum $name, string|object|null $origin = null)
 * @method static \Kirschbaum\Monitor\ControlStack stack()
 * @method static \Kirschbaum\Monitor\Trace\Trace trace()
 * @method static \Kirschbaum\Monitor\Breaker\CircuitBreaker breaker()
 * @method static \Kirschbaum\Monitor\Logging\StructuredLogger log(string|object $origin)
 * @method static \Kirschbaum\Monitor\Testing\MonitorFake returning(string|\BackedEnum $point, mixed $value)
 * @method static \Kirschbaum\Monitor\Testing\MonitorFake failing(string|\BackedEnum $point, \Throwable $exception)
 * @method static \Illuminate\Support\Collection<int, \Kirschbaum\Monitor\Outcome> outcomes(string|\BackedEnum|null $point = null)
 * @method static \Kirschbaum\Monitor\Testing\MonitorFake assertRan(string|\BackedEnum $point, ?\Closure $callback = null)
 * @method static \Kirschbaum\Monitor\Testing\MonitorFake assertNotRan(string|\BackedEnum $point, ?\Closure $callback = null)
 * @method static \Kirschbaum\Monitor\Testing\MonitorFake assertRanOnce(string|\BackedEnum $point)
 * @method static \Kirschbaum\Monitor\Testing\MonitorFake assertRanTimes(string|\BackedEnum $point, int $times)
 * @method static \Kirschbaum\Monitor\Testing\MonitorFake assertSucceeded(string|\BackedEnum $point, ?\Closure $callback = null)
 * @method static \Kirschbaum\Monitor\Testing\MonitorFake assertRecovered(string|\BackedEnum $point, \Closure|string|null $from = null)
 * @method static \Kirschbaum\Monitor\Testing\MonitorFake assertNotRecovered(string|\BackedEnum $point, \Closure|string|null $from = null)
 * @method static \Kirschbaum\Monitor\Testing\MonitorFake assertEscalated(string|\BackedEnum $point, \Closure|string|null $with = null)
 * @method static \Kirschbaum\Monitor\Testing\MonitorFake assertNotEscalated(string|\BackedEnum $point, \Closure|string|null $with = null)
 * @method static \Kirschbaum\Monitor\Testing\MonitorFake assertRefused(string|\BackedEnum $point)
 * @method static \Kirschbaum\Monitor\Testing\MonitorFake assertNotRefused(string|\BackedEnum $point)
 * @method static \Kirschbaum\Monitor\Testing\MonitorFake assertRetried(string|\BackedEnum $point, ?int $times = null)
 * @method static \Kirschbaum\Monitor\Testing\MonitorFake assertNotRetried(string|\BackedEnum $point, ?int $times = null)
 * @method static \Kirschbaum\Monitor\Testing\MonitorFake assertLimitBreached(string|\BackedEnum $point, string $limit)
 * @method static \Kirschbaum\Monitor\Testing\MonitorFake assertNothingEscalated()
 * @method static \Kirschbaum\Monitor\Testing\MonitorFake assertNothingRan()
 *
 * @see \Kirschbaum\Monitor\Monitor
 */
class Monitor extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Kirschbaum\Monitor\Monitor::class;
    }

    /**
     * Replace the runner with one that records every outcome.
     *
     * Control points still execute for real unless a canned value or failure
     * is registered for them with returning() or failing(). Assert afterwards
     * with assertRan() and friends. Calling this twice returns the same fake.
     */
    public static function fake(): MonitorFake
    {
        $current = static::isFake() ? static::getFacadeRoot() : null;

        if ($current instanceof MonitorFake) {
            return $current;
        }

        $app = static::getFacadeApplication();

        if ($app === null) {
            throw new RuntimeException('A facade application has not been set.');
        }

        $fake = new MonitorFake($app, $app->make(Runner::class));

        $app->instance(Runner::class, $fake);

        static::swap($fake);

        return $fake;
    }
}
