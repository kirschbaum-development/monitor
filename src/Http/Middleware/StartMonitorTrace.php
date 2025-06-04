<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Kirschbaum\Monitor\Facades\Monitor;
use Symfony\Component\HttpFoundation\Response;

class StartMonitorTrace
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var string $header */
        $header = Config::string('monitor.trace_header', 'X-Trace-Id');

        if (Monitor::trace()->hasNotStarted()) {
            $headerValue = $request->header($header);
            $traceId = is_string($headerValue) ? $headerValue : Str::uuid()->toString();
            Monitor::trace()->override($traceId);
        }

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set($header, Monitor::trace()->id());

        return $response;
    }
}
