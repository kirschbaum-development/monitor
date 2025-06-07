<?php

declare(strict_types=1);

namespace Tests\Unit;

use Kirschbaum\Monitor\Exceptions\NestedControlledBlockException;
use Kirschbaum\Monitor\Support\ControlledContext;

describe('ControlledContext', function () {
    beforeEach(function () {
        $this->context = new ControlledContext;
    });

    it('starts with no active context', function () {
        expect($this->context->isInside())->toBeFalse()
            ->and($this->context->current())->toBeNull();
    });

    it('tracks entering a controlled block', function () {
        $this->context->enter('test-block', 'uuid-123');

        expect($this->context->isInside())->toBeTrue()
            ->and($this->context->current())->toBe([
                'name' => 'test-block',
                'uuid' => 'uuid-123',
            ]);
    });

    it('clears context when exiting', function () {
        $this->context->enter('test-block', 'uuid-123');
        $this->context->exit();

        expect($this->context->isInside())->toBeFalse()
            ->and($this->context->current())->toBeNull();
    });

    it('prevents nested controlled blocks', function () {
        $this->context->enter('outer-block', 'uuid-outer');

        expect(fn () => $this->context->enter('inner-block', 'uuid-inner'))
            ->toThrow(
                NestedControlledBlockException::class,
                "Nested Controlled block detected: attempted to start 'inner-block' while already in 'outer-block' (uuid: uuid-outer)."
            );
    });

    it('allows sequential blocks after exit', function () {
        $this->context->enter('first-block', 'uuid-first');
        $this->context->exit();

        // Should not throw
        $this->context->enter('second-block', 'uuid-second');

        expect($this->context->current())->toBe([
            'name' => 'second-block',
            'uuid' => 'uuid-second',
        ]);
    });

    it('handles multiple exit calls gracefully', function () {
        $this->context->enter('test-block', 'uuid-123');
        $this->context->exit();
        $this->context->exit(); // Should not cause errors

        expect($this->context->isInside())->toBeFalse()
            ->and($this->context->current())->toBeNull();
    });
});
