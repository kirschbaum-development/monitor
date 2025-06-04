<?php

declare(strict_types=1);

namespace Tests\Unit;

use Kirschbaum\Monitor\Trace;
use LogicException;

it('starts a new trace with generated UUID', function () {
    $trace = new Trace;

    expect($trace->hasNotStarted())->toBeTrue()
        ->and($trace->hasStarted())->toBeFalse();

    $trace->start();

    expect($trace->hasStarted())->toBeTrue()
        ->and($trace->hasNotStarted())->toBeFalse();

    $traceId = $trace->id();
    expect($traceId)->toBeString()
        ->and(strlen($traceId))->toBe(36) // UUID length
        ->and($traceId)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i');
});

it('throws exception when starting already started trace', function () {
    $trace = new Trace;
    $trace->start();

    expect(fn () => $trace->start())
        ->toThrow(LogicException::class, 'Trace has already been started.');
});

it('throws exception when accessing ID of unstarted trace', function () {
    $trace = new Trace;

    expect(fn () => $trace->id())
        ->toThrow(LogicException::class, 'Trace ID has not been started.');
});

it('overrides trace ID with custom UUID', function () {
    $trace = new Trace;
    $customUuid = 'custom-test-uuid-12345';

    $trace->override($customUuid);

    expect($trace->hasStarted())->toBeTrue()
        ->and($trace->id())->toBe($customUuid);
});

it('pickup starts new trace when no trace ID provided and trace not started', function () {
    $trace = new Trace;

    expect($trace->hasNotStarted())->toBeTrue();

    $result = $trace->pickup();

    expect($result)->toBe($trace) // Should return same instance
        ->and($trace->hasStarted())->toBeTrue()
        ->and($trace->id())->toBeString()
        ->and(strlen($trace->id()))->toBe(36);
});

it('pickup overrides trace ID when custom trace ID provided and trace not started', function () {
    $trace = new Trace;
    $customUuid = 'pickup-custom-uuid-67890';

    expect($trace->hasNotStarted())->toBeTrue();

    $result = $trace->pickup($customUuid);

    expect($result)->toBe($trace) // Should return same instance
        ->and($trace->hasStarted())->toBeTrue()
        ->and($trace->id())->toBe($customUuid);
});

it('pickup returns same instance when trace already started', function () {
    $trace = new Trace;
    $trace->start();
    $originalId = $trace->id();

    // Should return same instance and not change the trace ID
    $result = $trace->pickup('should-not-override');

    expect($result)->toBe($trace)
        ->and($trace->id())->toBe($originalId); // Original ID preserved
});

it('pickup with custom ID returns same instance when trace already started', function () {
    $trace = new Trace;
    $customUuid = 'already-started-uuid';
    $trace->override($customUuid);

    // Should return same instance and not change the trace ID
    $result = $trace->pickup('different-uuid');

    expect($result)->toBe($trace)
        ->and($trace->id())->toBe($customUuid); // Original ID preserved
});

it('hasStarted returns correct boolean values', function () {
    $trace = new Trace;

    // Initially not started
    expect($trace->hasStarted())->toBeFalse();

    // After starting
    $trace->start();
    expect($trace->hasStarted())->toBeTrue();
});

it('hasNotStarted returns correct boolean values', function () {
    $trace = new Trace;

    // Initially not started
    expect($trace->hasNotStarted())->toBeTrue();

    // After starting
    $trace->start();
    expect($trace->hasNotStarted())->toBeFalse();
});

it('hasNotStarted returns false after override', function () {
    $trace = new Trace;

    expect($trace->hasNotStarted())->toBeTrue();

    $trace->override('override-test-uuid');

    expect($trace->hasNotStarted())->toBeFalse();
});

it('generates different UUIDs for multiple trace instances', function () {
    $trace1 = new Trace;
    $trace2 = new Trace;

    $trace1->start();
    $trace2->start();

    expect($trace1->id())->not->toBe($trace2->id());
});

it('can override trace ID multiple times before starting', function () {
    $trace = new Trace;

    $firstUuid = 'first-uuid';
    $secondUuid = 'second-uuid';

    $trace->override($firstUuid);
    expect($trace->id())->toBe($firstUuid);

    $trace->override($secondUuid);
    expect($trace->id())->toBe($secondUuid);
});

it('maintains state correctly through fluent interface', function () {
    $trace = new Trace;

    // Test fluent interface with generated UUID
    $result = $trace->pickup();
    expect($result)->toBe($trace)
        ->and($trace->hasStarted())->toBeTrue();

    // Test fluent interface when already started
    $originalId = $trace->id();
    $result2 = $trace->pickup('ignored-uuid');
    expect($result2)->toBe($trace)
        ->and($trace->id())->toBe($originalId);
});

it('handles empty string override - started but ID throws exception', function () {
    $trace = new Trace;

    $trace->override('');

    // Empty string is not null, so hasStarted() returns true
    expect($trace->hasStarted())->toBeTrue()
        ->and($trace->hasNotStarted())->toBeFalse();

    // But empty string is falsy, so id() throws exception
    expect(fn () => $trace->id())
        ->toThrow(LogicException::class, 'Trace ID has not been started.');
});

it('pickup handles null explicitly vs no parameters', function () {
    $trace1 = new Trace;
    $trace2 = new Trace;

    // Explicit null
    $trace1->pickup(null);

    // No parameter (defaults to null)
    $trace2->pickup();

    expect($trace1->hasStarted())->toBeTrue()
        ->and($trace2->hasStarted())->toBeTrue()
        ->and($trace1->id())->toBeString()
        ->and($trace2->id())->toBeString()
        ->and(strlen($trace1->id()))->toBe(36)
        ->and(strlen($trace2->id()))->toBe(36);
});

it('returns correct ID after successful start', function () {
    $trace = new Trace;
    $trace->start();

    $id = $trace->id();

    // This specifically tests line 45: return $this->traceId;
    expect($id)->toBeString()
        ->and($id)->toBe($trace->id()) // Should be consistent
        ->and(strlen($id))->toBe(36);
});
