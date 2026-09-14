<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Kirschbaum\Monitor\Control;
use Kirschbaum\Monitor\Facades\Monitor;
use Kirschbaum\Monitor\Policies\Once;
use Kirschbaum\Monitor\Risks\Duplicate;
use Kirschbaum\Monitor\Status;
use Tests\Fixtures\CardDeclined;
use Tests\Fixtures\Fatal;

function chargeOnce(string $key = 'invoice:1'): Control
{
    return Monitor::control('payment.charge')->once($key, ttl: 600);
}

describe('once', function (): void {
    it('refuses a second run for the same key inside the window', function (): void {
        $calls = 0;

        expect(chargeOnce()->run(function () use (&$calls): int {
            return ++$calls;
        }))->toBe(1);

        $second = chargeOnce()->attempt(function () use (&$calls): int {
            return ++$calls;
        });

        expect($calls)->toBe(1)
            ->and($second->status)->toBe(Status::Escalated)
            ->and($second->exception)->toBeInstanceOf(Duplicate::class)
            ->and($second->exception->key)->toBe('invoice:1')
            ->and($second->exception->originalRunId)->toHaveLength(26)
            ->and($second->timeline[1]['event'])->toBe('once.duplicate');
    });

    it('can be recovered from, and separate keys do not collide', function (): void {
        chargeOnce()->run(fn (): int => 1);

        expect(chargeOnce()->recover(Duplicate::class, fn (Duplicate $e): string => "already charged ({$e->key})")->run(fn (): int => 2))->toBe('already charged (invoice:1)')
            ->and(chargeOnce('invoice:2')->run(fn (): int => 3))->toBe(3);
    });

    it('releases the key when the run fails inside the policies, and keeps it when ensure() fails after', function (): void {
        chargeOnce()->attempt(fn () => throw new Fatal('gateway down'));
        expect(chargeOnce()->run(fn (): string => 'retried'))->toBe('retried');

        chargeOnce('invoice:9')->ensure(fn (): bool => false, 'not settled')->attempt(fn (): string => 'charged');
        expect(chargeOnce('invoice:9')->attempt(fn (): string => 'again')->exception)->toBeInstanceOf(Duplicate::class);
    });

    it('releases the key when a declared risk fires, since nothing was done', function (): void {
        chargeOnce()->recover(CardDeclined::class, fn (): string => 'declined')->run(fn () => throw new CardDeclined);

        expect(chargeOnce()->run(fn (): string => 'second try'))->toBe('second try');
    });

    it('expires with the window and honours the configured prefix and store', function (): void {
        config()->set('monitor.once.prefix', 'custom:');

        chargeOnce()->run(fn (): int => 1);

        expect(Cache::has('custom:payment.charge:invoice:1'))->toBeTrue();

        $this->travel(601)->seconds();

        expect(chargeOnce()->run(fn (): int => 2))->toBe(2)
            ->and(Once::key('k', 5)->describe())->toBe(['type' => 'once', 'key' => 'k', 'ttl' => 5])
            ->and(Monitor::control('a.b')->once('k')->describe()['policies'][0]['type'])->toBe('once');
    });

    it('runs before the breaker so a refused run never claims the key', function (): void {
        Monitor::breaker()->open('stripe', 60);

        chargeOnce()->breaker('stripe')->attempt(fn (): int => 1);

        Monitor::breaker()->close('stripe');

        expect(chargeOnce()->breaker('stripe')->run(fn (): int => 2))->toBe(2);
    });
});
