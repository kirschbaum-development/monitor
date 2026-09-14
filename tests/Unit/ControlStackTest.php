<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Context;
use Kirschbaum\Monitor\ControlStack;

describe('control stack', function (): void {
    it('pushes, pops and exposes the current point through Context', function (): void {
        $stack = resolve(ControlStack::class);

        expect($stack->current())->toBeNull()->and($stack->currentRunId())->toBeNull();

        $stack->push('a.b', 'run-1');
        $stack->push('c.d', 'run-2');

        expect($stack->depth())->toBe(2)
            ->and($stack->names())->toBe(['a.b', 'c.d'])
            ->and($stack->currentRunId())->toBe('run-2')
            ->and(Context::get('control_point'))->toBe('c.d');

        $stack->pop();

        expect(Context::get('control_point'))->toBe('a.b')->and($stack->currentRunId())->toBe('run-1');

        $stack->pop();

        expect($stack->isInside())->toBeFalse()->and(Context::get('control_point'))->toBeNull();
    });

    it('ignores malformed entries and can be cleared', function (): void {
        Context::addHidden(ControlStack::KEY, ['junk', ['point' => 1], ['point' => 'ok.one', 'run_id' => 'r']]);
        $stack = resolve(ControlStack::class);

        expect($stack->all())->toBe([['point' => 'ok.one', 'run_id' => 'r']]);

        $stack->clear();

        expect($stack->all())->toBe([]);
    });
});
