<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Trace;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Config;

/**
 * Registers Http::traced() and $pending->traced(), which add the current
 * trace to an outgoing request as traceparent and the legacy header.
 */
final class PropagatesTrace
{
    public static function register(Trace $trace): void
    {
        $headers = static fn (): array => [
            Config::string('monitor.trace.header', 'traceparent') => TraceParent::format($trace->id()),
            Config::string('monitor.trace.legacy_header', 'X-Trace-Id') => $trace->id(),
        ];

        PendingRequest::macro('traced', function () use ($headers): PendingRequest {
            /** @var PendingRequest $this */
            return $this->withHeaders($headers());
        });

        Factory::macro('traced', function () use ($headers): PendingRequest {
            /** @var Factory $this */
            return $this->withHeaders($headers());
        });
    }
}
