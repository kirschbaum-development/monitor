<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Context;
use Kirschbaum\Monitor\Exceptions\InvalidTraceId;
use Kirschbaum\Monitor\Trace\Trace;
use Kirschbaum\Monitor\Trace\TraceParent;

describe('trace', function (): void {
    beforeEach(fn () => resolve(Trace::class)->clear());

    it('starts a 32 hex trace on demand and keeps it', function (): void {
        $trace = resolve(Trace::class);

        expect($trace->hasStarted())->toBeFalse()->and($trace->current())->toBeNull();

        $id = $trace->id();

        expect($id)->toMatch('/^[0-9a-f]{32}$/')
            ->and($trace->id())->toBe($id)
            ->and($trace->pickup('ffffffffffffffffffffffffffffffff'))->toBe($id)
            ->and(Context::get('trace_id'))->toBe($id);
    });

    it('adopts a valid id and normalises a uuid', function (): void {
        $trace = resolve(Trace::class);

        expect($trace->pickup('9D2B4E8F-3A1C-4D5E-8F2A-1B3C4D5E6F7A'))->toBe('9d2b4e8f3a1c4d5e8f2a1b3c4d5e6f7a')
            ->and($trace->override('00000000000000000000000000000001'))->toBe('00000000000000000000000000000001')
            ->and($trace->start())->not->toBe('00000000000000000000000000000001');
    });

    it('rejects an invalid id', function (string $bad): void {
        expect(fn () => resolve(Trace::class)->override($bad))->toThrow(InvalidTraceId::class);
    })->with(['nope', '', '00000000000000000000000000000000', 'zz2b4e8f3a1c4d5e8f2a1b3c4d5e6f7a', '<script>']);

    it('parses and formats traceparent', function (): void {
        expect(TraceParent::parse('00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01'))->toBe('0af7651916cd43dd8448eb211c80319c')
            ->and(TraceParent::parse('00-00000000000000000000000000000000-b7ad6b7169203331-01'))->toBeNull()
            ->and(TraceParent::parse('00-0af7651916cd43dd8448eb211c80319c-0000000000000000-01'))->toBeNull()
            ->and(TraceParent::parse('garbage'))->toBeNull()
            ->and(TraceParent::parse(null))->toBeNull()
            ->and(TraceParent::format('0af7651916cd43dd8448eb211c80319c', 'b7ad6b7169203331'))->toBe('00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01')
            ->and(TraceParent::format('0af7651916cd43dd8448eb211c80319c', 'b7ad6b7169203331', false))->toEndWith('-00')
            ->and(TraceParent::format('0af7651916cd43dd8448eb211c80319c'))->toMatch('/^00-[0-9a-f]{32}-[0-9a-f]{16}-01$/');
    });
});
