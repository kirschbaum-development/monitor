<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Kirschbaum\Monitor\Trace trace()
 * @method static \Kirschbaum\Monitor\StructuredLogger log(string|object $origin = 'Monitor')
 * @method static \Kirschbaum\Monitor\LogTimer time()
 * @method static \Kirschbaum\Monitor\CircuitBreaker breaker()
 * @method static \Kirschbaum\Monitor\Controlled controlled(string $name, string|object|null $origin = null)
 * @method static \Kirschbaum\Monitor\Support\LogRedactor redactor()
 */
class Monitor extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Kirschbaum\Monitor\Monitor::class;
    }
}
