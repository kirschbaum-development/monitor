<?php

declare(strict_types=1);

use Illuminate\Support\Sleep;
use Kirschbaum\Monitor\Facades\Monitor;
use Kirschbaum\Monitor\Outcome;
use Kirschbaum\Monitor\Testing\MonitorFake;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Fixtures\CardDeclined;
use Tests\Fixtures\Fatal;
use Tests\Fixtures\PointNames;

describe('Monitor::fake()', function (): void {
    it('records outcomes while still running the point', function (): void {
        $fake = Monitor::fake();
        $ran = false;

        Monitor::control('payment.charge')->run(function () use (&$ran): string {
            $ran = true;

            return 'charged';
        });

        expect($fake)->toBeInstanceOf(MonitorFake::class)
            ->and($ran)->toBeTrue()
            ->and(Monitor::outcomes())->toHaveCount(1)
            ->and(Monitor::outcomes('payment.charge')[0]->value)->toBe('charged');

        Monitor::assertRan('payment.charge')
            ->assertRanTimes('payment.charge', 1)
            ->assertSucceeded('payment.charge')
            ->assertSucceeded('payment.charge', fn (Outcome $o): bool => $o->value === 'charged')
            ->assertNotRan('payment.refund')
            ->assertNothingEscalated();
    });

    it('returns a canned value without running the callback', function (): void {
        Monitor::fake()->returning('payment.charge', 'canned');
        $ran = false;

        $value = Monitor::control('payment.charge')->run(function () use (&$ran): string {
            $ran = true;

            return 'real';
        });

        expect($value)->toBe('canned')->and($ran)->toBeFalse();
        Monitor::assertSucceeded('payment.charge');
    });

    it('fails a point through its own corrections', function (): void {
        Monitor::fake()->failing('payment.charge', new CardDeclined('stolen_card'));

        $value = Monitor::control('payment.charge')
            ->recover(CardDeclined::class, fn (CardDeclined $e): string => "declined: {$e->declineCode}")
            ->run(fn (): string => 'never');

        expect($value)->toBe('declined: stolen_card');
        Monitor::assertRecovered('payment.charge')->assertRecovered('payment.charge', from: CardDeclined::class);
    });

    it('asserts escalation, refusal, retries and limits', function (): void {
        Sleep::fake();
        Monitor::fake();

        Monitor::control('payment.charge')->retry(times: 2, backoffMs: 0)->attempt(fn () => throw new Fatal('x'));
        Monitor::breaker()->open('stripe', 60);
        Monitor::control('payment.refund')->breaker('stripe')->attempt(fn (): int => 1);
        Monitor::control('search.index')->within(0.0001)->attempt(fn (): null => usleep(500));

        Monitor::assertEscalated('payment.charge')
            ->assertEscalated('payment.charge', with: Fatal::class)
            ->assertRetried('payment.charge')
            ->assertRetried('payment.charge', times: 2)
            ->assertRefused('payment.refund')
            ->assertLimitBreached('search.index', 'duration');
    });

    it('fails assertions with a readable message', function (): void {
        Monitor::fake();

        Monitor::control('payment.charge')->attempt(fn () => throw new Fatal);

        expect(fn () => Monitor::assertNotRan('payment.charge'))->toThrow(AssertionFailedError::class, 'ran, but was expected not to')
            ->and(fn () => Monitor::assertSucceeded('payment.charge'))->toThrow(AssertionFailedError::class, 'did not end succeeded; saw: escalated')
            ->and(fn () => Monitor::assertRan('payment.refund'))->toThrow(AssertionFailedError::class, 'did not run')
            ->and(fn () => Monitor::assertRan('payment.charge', fn (): bool => false))->toThrow(AssertionFailedError::class, 'no outcome matched')
            ->and(fn () => Monitor::assertRetried('payment.charge'))->toThrow(AssertionFailedError::class, 'was not retried')
            ->and(fn () => Monitor::assertRetried('payment.charge', 3))->toThrow(AssertionFailedError::class, 'exactly 3 times')
            ->and(fn () => Monitor::assertLimitBreached('payment.charge', 'duration'))->toThrow(AssertionFailedError::class, 'did not breach')
            ->and(fn () => Monitor::assertNothingEscalated())->toThrow(AssertionFailedError::class, 'escalated: payment.charge')
            ->and(fn () => Monitor::assertNothingRan())->toThrow(AssertionFailedError::class, 'ran: payment.charge')
            ->and(fn () => Monitor::assertRanTimes('payment.charge', 2))->toThrow(AssertionFailedError::class, 'ran 1 times instead of 2 times');
    });

    it('can forget what it recorded and swap canned behaviours', function (): void {
        $fake = Monitor::fake()->returning('a.b', 1)->failing('a.b', new Fatal);

        expect(Monitor::control('a.b')->attempt(fn (): int => 9)->escalated())->toBeTrue();

        $fake->returning('a.b', 2);

        expect(Monitor::control('a.b')->run(fn (): int => 9))->toBe(2);

        $fake->forget();
        Monitor::assertNothingRan();
    });

    it('asserts the negative half of every outcome', function (): void {
        Sleep::fake();
        Monitor::fake();

        Monitor::control('payment.charge')->run(fn (): int => 1);
        Monitor::control('payment.refund')->recover(CardDeclined::class, fn (): null => null)->run(fn () => throw new CardDeclined);
        Monitor::control('search.index')->retry(times: 1, backoffMs: 0)->attempt(fn () => throw new Fatal);

        Monitor::assertNotRan('payment.capture')
            ->assertNotRan('payment.charge', fn (Outcome $o): bool => $o->escalated())
            ->assertRanOnce('payment.charge')
            ->assertNotRecovered('payment.charge')
            ->assertNotRecovered('payment.refund', from: Fatal::class)
            ->assertRecovered('payment.refund', fn (Outcome $o): bool => $o->value === null)
            ->assertNotEscalated('payment.charge')
            ->assertNotEscalated('search.index', with: CardDeclined::class)
            ->assertEscalated('search.index', fn (Outcome $o): bool => $o->attempts === 2)
            ->assertNotRefused('payment.charge')
            ->assertNotRetried('payment.charge')
            ->assertNotRetried('search.index', times: 5);

        expect(fn () => Monitor::assertNotRan('payment.charge'))->toThrow(AssertionFailedError::class, 'ran, but was expected not to')
            ->and(fn () => Monitor::assertNotRan('payment.charge', fn (): bool => true))->toThrow(AssertionFailedError::class, 'matching the callback')
            ->and(fn () => Monitor::assertNotRecovered('payment.refund'))->toThrow(AssertionFailedError::class, 'ended recovered, but was expected not to')
            ->and(fn () => Monitor::assertNotEscalated('search.index'))->toThrow(AssertionFailedError::class, 'ended escalated')
            ->and(fn () => Monitor::assertNotRetried('search.index'))->toThrow(AssertionFailedError::class, 'was retried, but was expected not to be')
            ->and(fn () => Monitor::assertNotRetried('search.index', times: 1))->toThrow(AssertionFailedError::class, 'retried exactly 1 times');

        Monitor::breaker()->open('stripe', 60);
        Monitor::control('payment.capture')->breaker('stripe')->attempt(fn (): int => 1);

        expect(fn () => Monitor::assertNotRefused('payment.capture'))->toThrow(AssertionFailedError::class, 'ended refused');
    });

    it('accepts enum point names everywhere', function (): void {
        Monitor::fake()->returning(PointNames::Charge, 'canned');

        expect(Monitor::control(PointNames::Charge)->run(fn (): string => 'real'))->toBe('canned');

        Monitor::assertRan(PointNames::Charge)->assertSucceeded(PointNames::Charge)->assertNotRan(PointNames::Refund);
        expect(Monitor::outcomes(PointNames::Charge))->toHaveCount(1);
    });

    it('serialises outcomes to json', function (): void {
        Monitor::fake();
        $outcome = Monitor::control('payment.charge')->attempt(fn (): int => 1);

        expect(json_decode(json_encode($outcome), true)['status'])->toBe('succeeded')
            ->and($outcome->toArray()['point'])->toBe('payment.charge');
    });
});
