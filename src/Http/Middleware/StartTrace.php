<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Kirschbaum\Monitor\Trace\Trace;
use Kirschbaum\Monitor\Trace\TraceParent;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adopts the incoming trace or starts one, and writes it back on the response.
 *
 * W3C traceparent is read first, then the legacy header. Whatever arrives is
 * validated; an invalid value is replaced with a fresh trace and the
 * replacement is logged once at debug, so a client sending junk cannot put
 * junk on every log line of the request.
 */
class StartTrace
{
    public function __construct(private readonly Trace $trace) {}

    public function handle(Request $request, Closure $next): Response
    {
        $header = Config::string('monitor.trace.header', 'traceparent');
        $legacy = Config::string('monitor.trace.legacy_header', 'X-Trace-Id');

        if (! $this->trace->hasStarted()) {
            $this->trace->pickup($this->incoming($request, $header, $legacy));
        }

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set($header, TraceParent::format($this->trace->id()));
        $response->headers->set($legacy, $this->trace->id());

        return $response;
    }

    private function incoming(Request $request, string $header, string $legacy): ?string
    {
        $traceparent = $request->header($header);

        if (is_string($traceparent) && $traceparent !== '') {
            $id = TraceParent::parse($traceparent);

            if ($id !== null) {
                return $id;
            }

            Log::debug('[Monitor] replaced an invalid incoming traceparent', ['header' => $header]);

            return null;
        }

        $legacyValue = $request->header($legacy);

        if (is_string($legacyValue) && $legacyValue !== '') {
            $id = Trace::normalise($legacyValue);

            if ($id !== null) {
                return $id;
            }

            Log::debug('[Monitor] replaced an invalid incoming trace id', ['header' => $legacy]);
        }

        return null;
    }
}
