<?php

declare(strict_types=1);

use Illuminate\Support\Sleep;
use Kirschbaum\Monitor\Facades\Monitor;
use Kirschbaum\Monitor\Outcome;
use Kirschbaum\Monitor\Risks\BreakerOpen;
use Kirschbaum\Monitor\Status;
use Kirschbaum\Monitor\Support\ChildEscalations;
use Tests\Fixtures\CardDeclined;
use Tests\Fixtures\Fatal;

describe('nesting', function (): void {
    it('links a child to its parent and records the stack', function (): void {
        $child = null;

        $parent = Monitor::control('order.place')->attempt(function () use (&$child): string {
            $child = Monitor::control('payment.charge')->attempt(fn (): string => 'charged');

            return 'placed';
        });

        expect($child->parentId)->toBe($parent->id)
            ->and($child->stack)->toBe(['order.place', 'payment.charge'])
            ->and($parent->stack)->toBe(['order.place'])
            ->and($child->traceId)->toBe($parent->traceId)
            ->and(Monitor::stack()->depth())->toBe(0);
    });

    it('nests to any depth', function (): void {
        $depths = [];

        Monitor::control('a.one')->run(function () use (&$depths): void {
            $depths[] = Monitor::stack()->depth();
            Monitor::control('b.two')->run(function () use (&$depths): void {
                $depths[] = Monitor::stack()->depth();
                Monitor::control('c.three')->run(function () use (&$depths): void {
                    $depths[] = Monitor::stack()->names();
                });
            });
        });

        expect($depths)->toBe([1, 2, ['a.one', 'b.two', 'c.three']]);
    });

    it('hands a child escalation to the parent corrections', function (): void {
        $parent = Monitor::control('order.place')
            ->recover(CardDeclined::class, fn (): string => 'order held')
            ->attempt(fn (): string => Monitor::control('payment.charge')->run(fn () => throw new CardDeclined));

        expect($parent->status)->toBe(Status::Recovered)
            ->and($parent->value)->toBe('order held')
            ->and($parent->recoveredFrom)->toBe(CardDeclined::class);
    });

    it('surfaces a child refusal to the parent as BreakerOpen', function (): void {
        Monitor::breaker()->open('stripe', 60);

        $parent = Monitor::control('order.place')
            ->recover(BreakerOpen::class, fn (BreakerOpen $e): string => "queued: {$e->breaker}")
            ->attempt(fn (): string => Monitor::control('payment.charge')->breaker('stripe')->run(fn (): string => 'no'));

        expect($parent->value)->toBe('queued: stripe');
    });

    it('does not let the parent retry a child that exhausted its own attempts', function (): void {
        Sleep::fake();
        $childRuns = 0;

        $parent = Monitor::control('order.place')->retry(times: 3, backoffMs: 0)
            ->attempt(function () use (&$childRuns): string {
                return Monitor::control('payment.charge')->retry(times: 1, backoffMs: 0)->run(function () use (&$childRuns): string {
                    $childRuns++;

                    throw new Fatal('always');
                });
            });

        expect($childRuns)->toBe(2)
            ->and($parent->attempts)->toBe(1)
            ->and($parent->status)->toBe(Status::Escalated)
            ->and(ChildEscalations::pointFor($parent->exception))->toBe('payment.charge');
    });

    it('still lets the parent retry its own failures', function (): void {
        Sleep::fake();
        $calls = 0;

        $parent = Monitor::control('order.place')->retry(times: 2, backoffMs: 0)->attempt(function () use (&$calls): string {
            Monitor::control('payment.charge')->run(fn (): string => 'fine');
            $calls++;

            if ($calls < 3) {
                throw new Fatal('parent flake');
            }

            return 'ok';
        });

        expect($parent->attempts)->toBe(3)->and($parent->status)->toBe(Status::Succeeded);
    });

    it('records the child outcome as escalated even when the parent recovers', function (): void {
        $child = null;

        Monitor::control('order.place')
            ->recover(CardDeclined::class, fn (): string => 'held')
            ->run(function () use (&$child): string {
                $child = Monitor::control('payment.charge')->attempt(fn () => throw new CardDeclined);

                if ($child->escalated()) {
                    throw $child->exception;
                }

                return 'placed';
            });

        expect($child)->toBeInstanceOf(Outcome::class)
            ->and($child->status)->toBe(Status::Escalated);
    });
});
