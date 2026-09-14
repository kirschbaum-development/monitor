<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Queue;
use Kirschbaum\Monitor\ControlStack;
use Kirschbaum\Monitor\Facades\Monitor;
use Kirschbaum\Monitor\Queue\Middleware\WaitForBreaker;
use Kirschbaum\Monitor\Queue\RunControlPoint;
use Kirschbaum\Monitor\Risks\BreakerOpen;
use Kirschbaum\Monitor\Trace\PicksUpJobTrace;
use Workbench\Monitor\ControlPoints\Payments\ChargeCard;
use Workbench\Monitor\Support\StripeClient;

beforeEach(function (): void {
    StripeClient::$charge = null;
    config()->set('monitor.domains.map', ['Workbench\\Monitor\\ControlPoints\\' => null]);
});

describe('dispatchable control points', function (): void {
    it('pushes a job named and tagged after the point', function (): void {
        Queue::fake();

        ChargeCard::dispatch(48211, 12900)->onQueue('payments');

        Queue::assertPushedOn('payments', RunControlPoint::class, fn (RunControlPoint $job): bool => $job->displayName() === 'payment.charge'
            && $job->tags() === ['monitor:payment.charge', 'domain:Payments']
            && $job->point instanceof ChargeCard);
    });

    it('runs the point when the job is handled and records the outcome', function (): void {
        Monitor::fake();

        ChargeCard::dispatchSync(1, 100);

        Monitor::assertSucceeded('payment.charge', fn ($o): bool => $o->context === ['invoice' => 1, 'amount' => 100]);
    });

    it('fails the job with the escaped exception when the point escalates', function (): void {
        StripeClient::$charge = fn () => throw new RuntimeException('gateway exploded');

        expect(fn (): ?\Kirschbaum\Monitor\Outcome => (new RunControlPoint(new ChargeCard(1, 1)))->handle())->toThrow(RuntimeException::class, 'gateway exploded');
    });

    it('releases the job for the breaker retry-after when refused, unless told not to', function (): void {
        Monitor::breaker()->open('stripe', 45);

        $queued = Mockery::mock(Job::class);
        $queued->shouldReceive('release')->once()->with(45);
        $queued->shouldReceive('isReleased')->andReturn(true);

        $job = new RunControlPoint(new ChargeCard(1, 1));
        $job->setJob($queued);

        expect($job->handle()?->refused())->toBeTrue();

        expect(fn (): ?\Kirschbaum\Monitor\Outcome => (new RunControlPoint(new ChargeCard(1, 1), releaseWhenRefused: false))->handle())->toThrow(BreakerOpen::class);
    });
});

describe('WaitForBreaker middleware', function (): void {
    it('releases while the circuit is open and passes through when closed', function (): void {
        $job = new class
        {
            public ?int $releasedFor = null;

            public function release(int $delay): void
            {
                $this->releasedFor = $delay;
            }
        };
        $ran = false;
        $next = function (object $j) use (&$ran): string {
            $ran = true;

            return 'ran';
        };

        Monitor::breaker()->open('efiling', 30);
        expect((new WaitForBreaker('efiling'))->handle($job, $next))->toBeNull()->and($job->releasedFor)->toBe(30)->and($ran)->toBeFalse();

        Monitor::breaker()->close('efiling');
        expect((new WaitForBreaker('efiling'))->handle($job, $next))->toBe('ran');

        Monitor::breaker()->open('efiling', 60);
        expect((new WaitForBreaker('efiling', minimumDelay: 100))->handle($job, $next))->toBeNull()->and($job->releasedFor)->toBe(100)
            ->and((new WaitForBreaker('efiling'))->handle(new stdClass, $next))->toBeNull();
    });
});

describe('stack hand-off', function (): void {
    it('starts a job with an empty stack and the dispatching run id', function (): void {
        $stack = resolve(ControlStack::class);
        $stack->push('order.place', 'run-1', 'App\\X');
        $stack->push('payment.charge', 'run-2', 'App\\Y');

        resolve(PicksUpJobTrace::class)->handle();

        expect($stack->depth())->toBe(0)
            ->and($stack->dispatchedFrom())->toBe('run-2')
            ->and(Context::get('dispatched_from_run'))->toBe('run-2')
            ->and(Context::get('control_point'))->toBeNull()
            ->and(Monitor::trace()->hasStarted())->toBeTrue();

        $outcome = Monitor::control('search.index')->attempt(fn (): int => 1);
        expect($outcome->parentId)->toBeNull()->and($outcome->stack)->toBe(['search.index']);
    });

    it('does nothing to a job that inherited no stack', function (): void {
        resolve(PicksUpJobTrace::class)->handle();

        expect(resolve(ControlStack::class)->dispatchedFrom())->toBeNull();
    });
});
