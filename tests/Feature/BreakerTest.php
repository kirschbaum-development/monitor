<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Sleep;
use Kirschbaum\Monitor\Breaker\BreakerConfig;
use Kirschbaum\Monitor\Breaker\State;
use Kirschbaum\Monitor\Control;
use Kirschbaum\Monitor\Events\BreakerClosed;
use Kirschbaum\Monitor\Events\BreakerHalfOpen;
use Kirschbaum\Monitor\Events\BreakerOpened;
use Kirschbaum\Monitor\Events\PointRefused;
use Kirschbaum\Monitor\Facades\Monitor;
use Kirschbaum\Monitor\Policies\Breaker;
use Kirschbaum\Monitor\Risks\BreakerOpen;
use Kirschbaum\Monitor\Status;
use Tests\Fixtures\CardDeclined;
use Tests\Fixtures\Fatal;

function stripe(): Control
{
    return Monitor::control('payment.charge')->breaker('stripe', after: 2, within: 60, for: 100);
}

describe('breaker policy', function (): void {
    it('opens after the configured failures and refuses the next run', function (): void {
        Event::fake([BreakerOpened::class, PointRefused::class]);

        stripe()->attempt(fn () => throw new Fatal('1'));
        stripe()->attempt(fn () => throw new Fatal('2'));

        $calls = 0;
        $outcome = stripe()->attempt(function () use (&$calls): void {
            $calls++;
        });

        expect($calls)->toBe(0)
            ->and($outcome->status)->toBe(Status::Refused)
            ->and($outcome->refused())->toBeTrue()
            ->and($outcome->exception)->toBeInstanceOf(BreakerOpen::class)
            ->and($outcome->exception->breaker)->toBe('stripe')
            ->and($outcome->exception->retryAfterSeconds)->toBe(100)
            ->and($outcome->timeline[1]['event'])->toBe('breaker.refused')
            ->and(Monitor::breaker()->isOpen('stripe'))->toBeTrue();

        Event::assertDispatchedTimes(BreakerOpened::class, 1);
        Event::assertDispatched(PointRefused::class);
    });

    it('run() throws BreakerOpen when refused', function (): void {
        stripe()->attempt(fn () => throw new Fatal);
        stripe()->attempt(fn () => throw new Fatal);

        expect(fn (): mixed => stripe()->run(fn (): int => 1))->toThrow(BreakerOpen::class);
    });

    it('lets the point recover from its own refusal', function (): void {
        stripe()->attempt(fn () => throw new Fatal);
        stripe()->attempt(fn () => throw new Fatal);

        $outcome = stripe()->recover(BreakerOpen::class, fn (BreakerOpen $e): string => "later ({$e->breaker})")->attempt(fn (): int => 1);

        expect($outcome->status)->toBe(Status::Recovered)
            ->and($outcome->value)->toBe('later (stripe)')
            ->and($outcome->recoveredFrom)->toBe(BreakerOpen::class);
    });

    it('counts one failure per run, after retries', function (): void {
        Sleep::fake();

        Monitor::control('payment.charge')->breaker('stripe', after: 2)->retry(times: 3, backoffMs: 0)->attempt(failingTimes(10));

        expect(Monitor::breaker()->state('stripe')->failureCount())->toBe(1);
    });

    it('does not count exceptions the breaker is told to ignore', function (): void {
        $control = fn () => Monitor::control('payment.charge')->policy(Breaker::named('stripe')->after(1)->except([CardDeclined::class]));

        $control()->attempt(fn () => throw new CardDeclined);

        expect(Monitor::breaker()->isOpen('stripe'))->toBeFalse();

        $control()->attempt(fn () => throw new Fatal);

        expect(Monitor::breaker()->isOpen('stripe'))->toBeTrue();
    });

    it('half-opens after the open period, lets one probe through, and closes on success', function (): void {
        Event::fake([BreakerHalfOpen::class, BreakerClosed::class]);

        stripe()->attempt(fn () => throw new Fatal);
        stripe()->attempt(fn () => throw new Fatal);

        $this->travel(101)->seconds();

        expect(Monitor::breaker()->isOpen('stripe'))->toBeFalse();

        $probe = stripe()->attempt(fn (): string => 'probe ok');

        expect($probe->status)->toBe(Status::Succeeded)
            ->and($probe->timeline[1]['event'])->toBe('breaker.probe')
            ->and(Monitor::breaker()->state('stripe')->state)->toBe(State::Closed);

        Event::assertDispatched(BreakerHalfOpen::class);
        Event::assertDispatched(BreakerClosed::class);
    });

    it('refuses a second caller while a probe is in flight', function (): void {
        stripe()->attempt(fn () => throw new Fatal);
        stripe()->attempt(fn () => throw new Fatal);
        $this->travel(101)->seconds();

        $second = null;
        $first = stripe()->attempt(function () use (&$second): string {
            $second = stripe()->attempt(fn (): string => 'no');

            return 'probe';
        });

        expect($first->status)->toBe(Status::Succeeded)
            ->and($second->status)->toBe(Status::Refused);
    });

    it('reopens for a full period when the probe fails', function (): void {
        stripe()->attempt(fn () => throw new Fatal);
        stripe()->attempt(fn () => throw new Fatal);
        $this->travel(101)->seconds();

        stripe()->attempt(fn () => throw new Fatal('probe failed'));

        expect(Monitor::breaker()->isOpen('stripe'))->toBeTrue()
            ->and(Monitor::breaker()->retryAfter('stripe'))->toBe(100);
    });

    it('forgets failures outside the window', function (): void {
        stripe()->attempt(fn () => throw new Fatal);
        $this->travel(61)->seconds();
        stripe()->attempt(fn () => throw new Fatal);

        expect(Monitor::breaker()->isOpen('stripe'))->toBeFalse()
            ->and(Monitor::breaker()->state('stripe')->failureCount())->toBe(1);
    });

    it('describes itself with the effective configuration', function (): void {
        config()->set('monitor.breakers', ['after' => 9, 'within' => 90, 'for' => 900, 'prefix' => 'monitor:breaker:']);

        expect(Breaker::named('x')->describe())->toMatchArray(['type' => 'breaker', 'name' => 'x', 'after' => 9, 'within' => 90, 'for' => 900])
            ->and(Breaker::named('x')->after(1, 2)->for(3)->describe())->toMatchArray(['after' => 1, 'within' => 2, 'for' => 3]);
    });
});

describe('circuit breaker service', function (): void {
    it('starts closed', function (): void {
        expect(Monitor::breaker()->state('fresh')->isClosed())->toBeTrue()
            ->and(Monitor::breaker()->isClosed('fresh'))->toBeTrue()
            ->and(Monitor::breaker()->attempt('fresh')->allowed)->toBeTrue()
            ->and(Monitor::breaker()->retryAfter('fresh'))->toBe(0);
    });

    it('can be opened and closed by hand', function (): void {
        Event::fake([BreakerOpened::class, BreakerClosed::class]);

        Monitor::breaker()->open('efiling', 30);

        expect(Monitor::breaker()->isOpen('efiling'))->toBeTrue()
            ->and(Monitor::breaker()->retryAfter('efiling'))->toBe(30);

        Monitor::breaker()->close('efiling');

        expect(Monitor::breaker()->isOpen('efiling'))->toBeFalse();

        Event::assertDispatched(BreakerOpened::class);
        Event::assertDispatched(BreakerClosed::class);
    });

    it('does not announce a close that changes nothing', function (): void {
        Event::fake([BreakerClosed::class]);

        Monitor::breaker()->recordSuccess('quiet');

        Event::assertNotDispatched(BreakerClosed::class);
    });

    it('uses configured defaults for the standalone API', function (): void {
        config()->set('monitor.breakers.after', 1);

        Monitor::breaker()->recordFailure('api');

        expect(Monitor::breaker()->isOpen('api'))->toBeTrue()
            ->and(BreakerConfig::defaults()->after)->toBe(1);
    });

    it('keeps at most "after" failures in the window', function (): void {
        $config = new BreakerConfig(after: 2, within: 60, for: 10);

        Monitor::breaker()->recordFailure('cap', $config);
        Monitor::breaker()->recordFailure('cap', $config);

        expect(Monitor::breaker()->state('cap')->failureCount())->toBe(2)
            ->and(Monitor::breaker()->state('cap')->isOpen())->toBeTrue();
    });

    it('survives a malformed cache entry', function (): void {
        Cache::put('monitor:breaker:junk', ['state' => 'weird', 'failures' => 'no', 'opened_at' => 'x'], 60);

        expect(Monitor::breaker()->state('junk')->isClosed())->toBeTrue();
    });
});
