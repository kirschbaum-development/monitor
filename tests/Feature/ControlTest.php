<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Sleep;
use Kirschbaum\Monitor\Control;
use Kirschbaum\Monitor\Events\EscalationFailed;
use Kirschbaum\Monitor\Events\PointEnded;
use Kirschbaum\Monitor\Events\PointEscalated;
use Kirschbaum\Monitor\Events\PointLimitBreached;
use Kirschbaum\Monitor\Events\PointRecovered;
use Kirschbaum\Monitor\Events\PointStarted;
use Kirschbaum\Monitor\Exceptions\InvalidControlPoint;
use Kirschbaum\Monitor\Exceptions\InvalidPointName;
use Kirschbaum\Monitor\Exceptions\InvalidProfile;
use Kirschbaum\Monitor\Facades\Monitor;
use Kirschbaum\Monitor\Outcome;
use Kirschbaum\Monitor\Risks\EnsureFailed;
use Kirschbaum\Monitor\Status;
use Tests\Fixtures\BrokenEscalation;
use Tests\Fixtures\CardDeclined;
use Tests\Fixtures\PagePayments;

beforeEach(function (): void {
    PagePayments::$received = null;
});

describe('terminals', function (): void {
    it('run() returns the value', function (): void {
        expect(Monitor::control('payment.charge')->run(fn (): string => 'charged'))->toBe('charged');
    });

    it('attempt() returns a succeeded outcome with every field set', function (): void {
        $outcome = Monitor::control('payment.charge', $this)->with(['invoice' => 7])->attempt(fn (): string => 'charged');

        expect($outcome)->toBeInstanceOf(Outcome::class)
            ->and($outcome->status)->toBe(Status::Succeeded)
            ->and($outcome->succeeded())->toBeTrue()
            ->and($outcome->hasValue())->toBeTrue()
            ->and($outcome->value)->toBe('charged')
            ->and($outcome->point)->toBe('payment.charge')
            ->and($outcome->id)->toHaveLength(26)
            ->and($outcome->parentId)->toBeNull()
            ->and($outcome->traceId)->toMatch('/^[0-9a-f]{32}$/')
            ->and($outcome->origin)->toBe(static::class)
            ->and($outcome->attempts)->toBe(1)
            ->and($outcome->durationMs)->toBeGreaterThanOrEqual(0.0)
            ->and($outcome->exception)->toBeNull()
            ->and($outcome->recoveredFrom)->toBeNull()
            ->and($outcome->limitsBreached)->toBe([])
            ->and($outcome->context)->toBe(['invoice' => 7])
            ->and($outcome->stack)->toBe(['payment.charge'])
            ->and($outcome->timeline[0]['event'])->toBe('started');
    });

    it('run() throws what escalated', function (): void {
        expect(fn () => Monitor::control('payment.charge')->run(fn () => throw new CardDeclined))
            ->toThrow(CardDeclined::class);
    });

    it('attempt() never throws for what the operation did', function (): void {
        $outcome = Monitor::control('payment.charge')->attempt(fn () => throw new CardDeclined('lost_card'));

        expect($outcome->status)->toBe(Status::Escalated)
            ->and($outcome->escalated())->toBeTrue()
            ->and($outcome->hasValue())->toBeFalse()
            ->and($outcome->value)->toBeNull()
            ->and($outcome->exception)->toBeInstanceOf(CardDeclined::class)
            ->and($outcome->toArray()['exception'])->toBe(['class' => CardDeclined::class, 'message' => 'Card declined: lost_card']);
    });

    it('rejects a name that does not match the pattern', function (string $name): void {
        expect(fn () => Monitor::control($name))->toThrow(InvalidPointName::class);
    })->with(['Payment', 'payment', 'payment.', 'payment.Charge', 'payment charge', '']);

    it('accepts dotted lowercase names with digits and underscores', function (): void {
        expect(Monitor::control('court.e_filing.submit_v2')->name())->toBe('court.e_filing.submit_v2');
    });
});

describe('risks and corrections', function (): void {
    it('uses the correction return value as the result', function (): void {
        $value = Monitor::control('payment.charge')
            ->recover(CardDeclined::class, fn (CardDeclined $e): array => ['declined' => $e->declineCode])
            ->run(fn () => throw new CardDeclined('insufficient_funds'));

        expect($value)->toBe(['declined' => 'insufficient_funds']);
    });

    it('treats a null correction as a null result, not as unhandled', function (): void {
        $outcome = Monitor::control('payment.charge')
            ->recover(CardDeclined::class, fn (): null => null)
            ->attempt(fn () => throw new CardDeclined);

        expect($outcome->status)->toBe(Status::Recovered)
            ->and($outcome->recovered())->toBeTrue()
            ->and($outcome->hasValue())->toBeTrue()
            ->and($outcome->value)->toBeNull()
            ->and($outcome->recoveredFrom)->toBe(CardDeclined::class)
            ->and($outcome->exception)->toBeInstanceOf(CardDeclined::class);
    });

    it('treats false and zero corrections as values too', function (mixed $value): void {
        expect(Monitor::control('payment.charge')->recover(CardDeclined::class, fn (): mixed => $value)->run(fn () => throw new CardDeclined))
            ->toBe($value);
    })->with([false, 0, '']);

    it('matches the first declared risk that fits, in declaration order', function (): void {
        $value = Monitor::control('payment.charge')
            ->recover(RuntimeException::class, fn (): string => 'runtime')
            ->recover(CardDeclined::class, fn (): string => 'declined')
            ->run(fn () => throw new CardDeclined);

        expect($value)->toBe('runtime');
    });

    it('hands the correction the exception and a provisional outcome', function (): void {
        $seen = null;

        Monitor::control('payment.charge', $this)->with(['invoice' => 9])
            ->recover(CardDeclined::class, function (CardDeclined $e, Outcome $partial) use (&$seen): string {
                $seen = $partial;

                return 'ok';
            })
            ->run(fn () => throw new CardDeclined);

        expect($seen)->toBeInstanceOf(Outcome::class)
            ->and($seen->point)->toBe('payment.charge')
            ->and($seen->context)->toBe(['invoice' => 9])
            ->and($seen->exception)->toBeInstanceOf(CardDeclined::class)
            ->and($seen->attempts)->toBe(1);
    });

    it('escalates with the correction\'s own exception when the correction throws', function (): void {
        $outcome = Monitor::control('payment.charge')
            ->recover(CardDeclined::class, fn () => throw new DomainException('cannot recover'))
            ->attempt(fn () => throw new CardDeclined);

        expect($outcome->status)->toBe(Status::Escalated)
            ->and($outcome->exception)->toBeInstanceOf(DomainException::class)
            ->and($outcome->recoveredFrom)->toBeNull();
    });

    it('lets a correction rethrow to escalate the original', function (): void {
        expect(fn () => Monitor::control('payment.charge')
            ->recover(CardDeclined::class, fn (CardDeclined $e) => throw $e)
            ->run(fn () => throw new CardDeclined))
            ->toThrow(CardDeclined::class);
    });

    it('supports Throwable as an explicit catch-all', function (): void {
        $control = Monitor::control('payment.charge')->recover(Throwable::class, fn (): string => 'anything');

        expect($control->hasCatchAll())->toBeTrue()
            ->and($control->run(fn () => throw new Error('fatal')))->toBe('anything');
    });

    it('rejects a risk that is not a throwable class', function (): void {
        expect(fn () => Monitor::control('payment.charge')->recover('NotAClass', fn (): null => null))
            ->toThrow(InvalidControlPoint::class);
    });
});

describe('escalation', function (): void {
    it('calls a closure escalation with the outcome and still propagates', function (): void {
        $seen = null;

        $outcome = Monitor::control('payment.charge')
            ->escalate(function (Outcome $o) use (&$seen): void {
                $seen = $o;
            })
            ->attempt(fn () => throw new CardDeclined);

        expect($seen)->toBe($outcome)
            ->and($outcome->status)->toBe(Status::Escalated)
            ->and($outcome->exception)->toBeInstanceOf(CardDeclined::class);
    });

    it('resolves an escalation class from the container', function (): void {
        Monitor::control('payment.charge')->escalate(PagePayments::class)->attempt(fn () => throw new CardDeclined);

        expect(PagePayments::$received)->toBeInstanceOf(Outcome::class)
            ->and(PagePayments::$received->point)->toBe('payment.charge');
    });

    it('does not escalate a recovered failure', function (): void {
        Monitor::control('payment.charge')
            ->recover(CardDeclined::class, fn (): string => 'ok')
            ->escalate(PagePayments::class)
            ->run(fn () => throw new CardDeclined);

        expect(PagePayments::$received)->toBeNull();
    });

    it('records a failing escalation and keeps the original exception', function (): void {
        Event::fake([EscalationFailed::class]);

        expect(fn () => Monitor::control('payment.charge')->escalate(BrokenEscalation::class)->run(fn () => throw new CardDeclined))
            ->toThrow(CardDeclined::class);

        Event::assertDispatched(EscalationFailed::class, fn (EscalationFailed $e): bool => $e->exception instanceof LogicException && $e->outcome->point === 'payment.charge');
    });

    it('rejects an escalation class that is not an Escalation', function (): void {
        expect(fn () => Monitor::control('payment.charge')->escalate(stdClass::class))
            ->toThrow(InvalidControlPoint::class);
    });
});

describe('limits', function (): void {
    it('fails the run with EnsureFailed when a post-condition does not hold', function (): void {
        $outcome = Monitor::control('payment.charge')
            ->ensure(fn (array $r): bool => $r['settled'], 'charge not settled')
            ->attempt(fn (): array => ['settled' => false]);

        expect($outcome->status)->toBe(Status::Escalated)
            ->and($outcome->exception)->toBeInstanceOf(EnsureFailed::class)
            ->and($outcome->exception->reason)->toBe('charge not settled')
            ->and($outcome->exception->value)->toBe(['settled' => false]);
    });

    it('lets a correction recover from EnsureFailed', function (): void {
        $value = Monitor::control('payment.charge')
            ->ensure(fn (array $r): bool => $r['settled'])
            ->recover(EnsureFailed::class, fn (EnsureFailed $e): string => 'unsettled')
            ->run(fn (): array => ['settled' => false]);

        expect($value)->toBe('unsettled');
    });

    it('records a duration breach without failing the run', function (): void {
        Event::fake([PointLimitBreached::class]);

        $outcome = Monitor::control('payment.charge')->within(0.001)->attempt(function (): string {
            Sleep::usleep(3000);

            return 'slow but fine';
        });

        expect($outcome->status)->toBe(Status::Succeeded)
            ->and($outcome->value)->toBe('slow but fine')
            ->and($outcome->breachedLimit('duration'))->toBeTrue()
            ->and($outcome->limitsBreached['duration']['threshold'])->toBe(1.0)
            ->and($outcome->limitsBreached['duration']['actual'])->toBeGreaterThan(1.0);

        Event::assertDispatched(PointLimitBreached::class, fn (PointLimitBreached $e): bool => $e->limit === 'duration' && $e->run->point === 'payment.charge');
    });

    it('does not record a breach inside the limit', function (): void {
        expect(Monitor::control('payment.charge')->within(10)->attempt(fn (): int => 1)->limitsBreached)->toBe([]);
    });
});

describe('events and context', function (): void {
    it('dispatches started, recovered and ended in order with the same run id', function (): void {
        Event::fake([PointStarted::class, PointRecovered::class, PointEscalated::class, PointEnded::class]);

        $outcome = Monitor::control('payment.charge')
            ->recover(CardDeclined::class, fn (): string => 'ok')
            ->attempt(fn () => throw new CardDeclined);

        Event::assertDispatched(PointStarted::class, fn (PointStarted $e): bool => $e->run->id === $outcome->id);
        Event::assertDispatched(PointRecovered::class, fn (PointRecovered $e): bool => $e->outcome === $outcome);
        Event::assertNotDispatched(PointEscalated::class);
        Event::assertDispatched(PointEnded::class, fn (PointEnded $e): bool => $e->outcome === $outcome);
    });

    it('exposes the trace id and current point through Context while running, and clears them after', function (): void {
        $during = null;

        Monitor::control('payment.charge')->run(function () use (&$during): void {
            $during = [Context::get('trace_id'), Context::get('control_point')];
        });

        expect($during[0])->toMatch('/^[0-9a-f]{32}$/')
            ->and($during[1])->toBe('payment.charge')
            ->and(Context::get('control_point'))->toBeNull()
            ->and(Monitor::stack()->isInside())->toBeFalse();
    });

    it('clears the stack even when the operation escalates', function (): void {
        Monitor::control('payment.charge')->attempt(fn () => throw new CardDeclined);

        expect(Monitor::stack()->depth())->toBe(0);
    });
});

describe('profiles and description', function (): void {
    it('applies a configured profile and lets the point override it', function (): void {
        config()->set('monitor.profiles.external', [
            'retry' => ['times' => 4, 'backoff_ms' => 10],
            'breaker' => ['after' => 3, 'within' => 30, 'for' => 60],
            'within' => 7,
        ]);

        $control = Monitor::control('payment.charge')->profile('external')->retry(times: 1)->within(2);
        $described = $control->describe();

        expect($described['profile'])->toBe('external')
            ->and($described['policies'])->toHaveCount(2)
            ->and($described['policies'][0]['type'])->toBe('breaker')
            ->and($described['policies'][0]['name'])->toBe('payment.charge')
            ->and($described['policies'][1]['type'])->toBe('retry')
            ->and($described['policies'][1]['times'])->toBe(1)
            ->and($described['limits'][0])->toBe(['type' => 'within', 'seconds' => 2.0]);
    });

    it('rejects an unknown profile at declaration time', function (): void {
        expect(fn () => Monitor::control('payment.charge')->profile('nope'))->toThrow(InvalidProfile::class);
    });

    it('describes the point for the inventory', function (): void {
        $described = Monitor::control('payment.charge', $this)
            ->transaction(retries: 1)
            ->recover(CardDeclined::class, fn (): null => null)
            ->escalate(PagePayments::class)
            ->ensure(fn (): bool => true, 'settled')
            ->attempts(3)
            ->describe();

        expect($described)->toMatchArray([
            'point' => 'payment.charge',
            'origin' => static::class,
            'risks' => [CardDeclined::class],
            'catch_all' => false,
            'escalation' => PagePayments::class,
        ])
            ->and($described['policies'][0]['type'])->toBe('transaction')
            ->and($described['limits'])->toBe([['type' => 'attempts', 'max' => 3], ['type' => 'ensure', 'reason' => 'settled']]);
    });

    it('describes a closure escalation as such and defaults the origin', function (): void {
        $described = Monitor::control('payment.charge')->escalate(fn (): null => null)->describe();

        expect($described['escalation'])->toBe('closure')
            ->and($described['origin'])->toBe(Control::class);
    });

    it('derives the domain from the origin namespace and allows an override', function (): void {
        config()->set('monitor.domains.map', ['App\\Services\\' => null]);

        expect(Monitor::control('payment.charge', 'App\\Services\\Payments\\StripeCharger')->resolvedDomain())->toBe('Payments')
            ->and(Monitor::control('payment.charge', 'App\\Services\\Payments\\StripeCharger')->domain('Billing')->resolvedDomain())->toBe('Billing');
    });
});
