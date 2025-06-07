<?php

declare(strict_types=1);

namespace Tests\Unit;

use Exception;
use Kirschbaum\Monitor\Data\ControlledFailureMeta;
use RuntimeException;

describe('ControlledFailureMeta', function () {
    it('creates meta with success (no exception)', function () {
        $meta = new ControlledFailureMeta(
            name: 'test-operation',
            id: 'block-123',
            traceId: 'trace-456',
            attempt: 1,
            durationMs: 250.5,
            exception: null,
            context: ['user_id' => 123],
            breakerTripped: false,
            escalated: false,
        );

        expect($meta->succeeded())->toBeTrue()
            ->and($meta->failed())->toBeFalse()
            ->and($meta->wasRetried())->toBeFalse();
    });

    it('creates meta with failure (with exception)', function () {
        $exception = new RuntimeException('Test error');

        $meta = new ControlledFailureMeta(
            name: 'failing-operation',
            id: 'block-456',
            traceId: 'trace-789',
            attempt: 2,
            durationMs: 150.0,
            exception: $exception,
            context: ['operation' => 'critical'],
            breakerTripped: true,
            escalated: true,
        );

        expect($meta->failed())->toBeTrue()
            ->and($meta->succeeded())->toBeFalse()
            ->and($meta->wasRetried())->toBeTrue();
    });

    it('detects retry attempts correctly', function () {
        $firstAttempt = new ControlledFailureMeta(
            name: 'test',
            id: 'id',
            traceId: 'trace',
            attempt: 1,
            durationMs: 100.0,
            exception: null
        );

        $secondAttempt = new ControlledFailureMeta(
            name: 'test',
            id: 'id',
            traceId: 'trace',
            attempt: 2,
            durationMs: 100.0,
            exception: null
        );

        expect($firstAttempt->wasRetried())->toBeFalse()
            ->and($secondAttempt->wasRetried())->toBeTrue();
    });

    it('converts success to array correctly', function () {
        $meta = new ControlledFailureMeta(
            name: 'success-operation',
            id: 'block-success',
            traceId: 'trace-success',
            attempt: 1,
            durationMs: 300.75,
            exception: null,
            context: ['user_id' => 456, 'action' => 'create'],
            breakerTripped: false,
            escalated: false,
        );

        $array = $meta->toArray();

        expect($array)->toBe([
            'controlled_block' => 'success-operation',
            'controlled_block_id' => 'block-success',
            'trace_id' => 'trace-success',
            'attempt' => 1,
            'duration_ms' => 300.75,
            'status' => 'succeeded',
            'breaker_tripped' => false,
            'escalated' => false,
            'exception' => null,
            'block_context' => ['user_id' => 456, 'action' => 'create'],
        ]);
    });

    it('converts failure to array correctly', function () {
        $exception = new RuntimeException('Database connection failed');

        $meta = new ControlledFailureMeta(
            name: 'db-operation',
            id: 'block-error',
            traceId: 'trace-error',
            attempt: 3,
            durationMs: 2500.0,
            exception: $exception,
            context: ['query' => 'SELECT * FROM users'],
            breakerTripped: true,
            escalated: true,
        );

        $array = $meta->toArray();

        expect($array['controlled_block'])->toBe('db-operation')
            ->and($array['controlled_block_id'])->toBe('block-error')
            ->and($array['trace_id'])->toBe('trace-error')
            ->and($array['attempt'])->toBe(3)
            ->and($array['duration_ms'])->toBe(2500.0)
            ->and($array['status'])->toBe('failed')
            ->and($array['breaker_tripped'])->toBeTrue()
            ->and($array['escalated'])->toBeTrue()
            ->and($array['block_context'])->toBe(['query' => 'SELECT * FROM users'])
            ->and($array['exception'])->toBeArray()
            ->and($array['exception']['class'])->toBe(RuntimeException::class)
            ->and($array['exception']['message'])->toBe('Database connection failed')
            ->and($array['exception']['file'])->toBeString()
            ->and($array['exception']['line'])->toBeInt();
    });

    it('handles default values correctly', function () {
        $meta = new ControlledFailureMeta(
            name: 'minimal-operation',
            id: 'minimal-id',
            traceId: 'minimal-trace',
            attempt: 1,
            durationMs: 50.0,
            exception: null
        );

        $array = $meta->toArray();

        expect($array['block_context'])->toBe([])
            ->and($array['breaker_tripped'])->toBeFalse()
            ->and($array['escalated'])->toBeFalse()
            ->and($array['exception'])->toBeNull();
    });

    it('builds exception data with different exception types', function () {
        $exceptions = [
            new RuntimeException('Runtime error'),
            new Exception('Generic exception'),
            new \InvalidArgumentException('Invalid argument'),
        ];

        foreach ($exceptions as $exception) {
            $meta = new ControlledFailureMeta(
                name: 'test',
                id: 'test-id',
                traceId: 'test-trace',
                attempt: 1,
                durationMs: 100.0,
                exception: $exception
            );

            $array = $meta->toArray();

            expect($array['exception']['class'])->toBe(get_class($exception))
                ->and($array['exception']['message'])->toBe($exception->getMessage())
                ->and($array['exception']['file'])->toBeString()
                ->and($array['exception']['line'])->toBeInt();
        }
    });
});
