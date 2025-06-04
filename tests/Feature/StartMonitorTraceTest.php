<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Kirschbaum\Monitor\Facades\Monitor;
use Kirschbaum\Monitor\Http\Middleware\StartMonitorTrace;

it('starts trace when none exists', function () {
    // Ensure no trace is started
    expect(Monitor::trace()->hasStarted())->toBeFalse();

    $middleware = new StartMonitorTrace;
    $request = Request::create('/test');

    $response = $middleware->handle($request, function ($request) {
        // Verify trace is started during request
        expect(Monitor::trace()->hasStarted())->toBeTrue();

        return new Response('Test response');
    });

    // Verify trace ID is set in response header
    expect($response->headers->get('X-Trace-Id'))->not()->toBeEmpty();
    expect($response->headers->get('X-Trace-Id'))->toBe(Monitor::trace()->id());
});

it('uses existing trace ID from header', function () {
    $existingTraceId = Str::uuid()->toString();

    $middleware = new StartMonitorTrace;
    $request = Request::create('/test');
    $request->headers->set('X-Trace-Id', $existingTraceId);

    $response = $middleware->handle($request, function ($request) use ($existingTraceId) {
        expect(Monitor::trace()->id())->toBe($existingTraceId);

        return new Response('Test response');
    });

    expect($response->headers->get('X-Trace-Id'))->toBe($existingTraceId);
});

it('preserves existing trace if already started', function () {
    // Start a trace manually
    Monitor::trace()->start();
    $originalTraceId = Monitor::trace()->id();

    $middleware = new StartMonitorTrace;
    $request = Request::create('/test');
    $request->headers->set('X-Trace-Id', Str::uuid()->toString());

    $response = $middleware->handle($request, function ($request) use ($originalTraceId) {
        // Should keep the original trace ID, not the one from header
        expect(Monitor::trace()->id())->toBe($originalTraceId);

        return new Response('Test response');
    });

    expect($response->headers->get('X-Trace-Id'))->toBe($originalTraceId);
});

it('uses custom trace header from config', function () {
    config()->set('monitor.trace_header', 'Custom-Trace-Header');

    $customTraceId = Str::uuid()->toString();

    $middleware = new StartMonitorTrace;
    $request = Request::create('/test');
    $request->headers->set('Custom-Trace-Header', $customTraceId);

    $response = $middleware->handle($request, function ($request) use ($customTraceId) {
        expect(Monitor::trace()->id())->toBe($customTraceId);

        return new Response('Test response');
    });

    expect($response->headers->get('Custom-Trace-Header'))->toBe($customTraceId);
});

it('generates new trace ID when header value is invalid', function () {
    $middleware = new StartMonitorTrace;
    $request = Request::create('/test');
    // Set invalid trace ID (array instead of string)
    $request->headers->set('X-Trace-Id', ['invalid', 'trace', 'id']);

    $response = $middleware->handle($request, function ($request) {
        expect(Monitor::trace()->hasStarted())->toBeTrue();
        expect(Monitor::trace()->id())->toBeString();

        return new Response('Test response');
    });

    expect($response->headers->get('X-Trace-Id'))->toBeString();
    expect($response->headers->get('X-Trace-Id'))->not()->toBe('Array');
});
