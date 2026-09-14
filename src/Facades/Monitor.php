<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Facades;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Kirschbaum\Monitor\Runner;
use Kirschbaum\Monitor\Testing\MonitorFake;

/**
 * @method static \Kirschbaum\Monitor\Control control(string $name, string|object|null $origin = null)
 * @method static \Kirschbaum\Monitor\ControlStack stack()
 * @method static \Kirschbaum\Monitor\Trace\Trace trace()
 * @method static \Kirschbaum\Monitor\Breaker\CircuitBreaker breaker()
 * @method static \Kirschbaum\Monitor\Logging\StructuredLogger log(string|object $origin)
 * @method static \Kirschbaum\Monitor\Testing\MonitorFake failing(string $point, \Throwable $exception)
 * @method static \Kirschbaum\Monitor\Testing\MonitorFake returning(string $point, mixed $value)
 * @method static \Kirschbaum\Monitor\Testing\MonitorFake assertRan(string $point, ?\Closure $callback = null)
 * @method static \Kirschbaum\Monitor\Testing\MonitorFake assertRanTimes(string $point, int $times)
 * @method static \Kirschbaum\Monitor\Testing\MonitorFake assertNeverRan(string $point)
 * @method static \Kirschbaum\Monitor\Testing\MonitorFake assertSucceeded(string $point, ?\Closure $callback = null)
 * @method static \Kirschbaum\Monitor\Testing\MonitorFake assertRecovered(string $point, ?string $from = null)
 * @method static \Kirschbaum\Monitor\Testing\MonitorFake assertEscalated(string $point, ?string $with = null)
 * @method static \Kirschbaum\Monitor\Testing\MonitorFake assertRefused(string $point)
 * @method static \Kirschbaum\Monitor\Testing\MonitorFake assertRetried(string $point, ?int $times = null)
 * @method static \Kirschbaum\Monitor\Testing\MonitorFake assertLimitBreached(string $point, string $limit)
 * @method static \Kirschbaum\Monitor\Testing\MonitorFake assertNothingEscalated()
 * @method static \Kirschbaum\Monitor\Testing\MonitorFake assertNothingRan()
 * @method static list<\Kirschbaum\Monitor\Outcome> outcomes(?string $point = null)
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
     * with assertRan() and friends.
     *
     * @param  array<string, mixed>  $returning  canned values keyed by point name
     */
    public static function fake(array $returning = []): MonitorFake
    {
        $app = Container::getInstance();
        $fake = new MonitorFake($app, $app->make(Runner::class));

        foreach ($returning as $point => $value) {
            $fake->returning($point, $value);
        }

        $app->instance(Runner::class, $fake);

        static::swap($fake);

        return $fake;
    }
}
