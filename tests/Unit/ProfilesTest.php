<?php

declare(strict_types=1);

use Kirschbaum\Monitor\Exceptions\InvalidProfile;
use Kirschbaum\Monitor\Support\Profiles;
use Tests\Fixtures\Fatal;

describe('profiles', function (): void {
    it('lists the shipped profiles', function (): void {
        expect(Profiles::names())->toBe(['external', 'database', 'messaging', 'internal'])
            ->and(Profiles::exists('external'))->toBeTrue()
            ->and(Profiles::exists('nope'))->toBeFalse();
    });

    it('builds every policy and limit from a definition', function (): void {
        config()->set('monitor.profiles.full', [
            'retry' => ['times' => '3', 'backoff_ms' => 10, 'multiplier' => 1.5, 'jitter' => false, 'on' => [Fatal::class, 'NotAClass'], 'except' => []],
            'transaction' => ['retries' => 1, 'connection' => 'other', 'on' => [Fatal::class], 'backoff_ms' => 5],
            'breaker' => ['name' => 'shared', 'after' => 2, 'within' => 20, 'for' => 200, 'except' => [Fatal::class]],
            'within' => 3,
            'attempts' => 4,
        ]);

        $policies = Profiles::policies('full', 'x.y');

        expect(array_keys($policies))->toBe(['retry', 'transaction', 'breaker'])
            ->and($policies['retry']->describe())->toMatchArray(['times' => 3, 'backoff_ms' => 10, 'multiplier' => 1.5, 'jitter' => false, 'on' => [Fatal::class]])
            ->and($policies['transaction']->describe())->toMatchArray(['retries' => 1, 'connection' => 'other', 'on' => [Fatal::class], 'backoff_ms' => 5])
            ->and($policies['breaker']->describe())->toMatchArray(['name' => 'shared', 'after' => 2, 'within' => 20, 'for' => 200, 'except' => [Fatal::class]])
            ->and(Profiles::within('full')?->seconds)->toBe(3.0)
            ->and(Profiles::attempts('full')?->max)->toBe(4);
    });

    it('names an unnamed breaker after the point and leaves unset limits null', function (): void {
        config()->set('monitor.profiles.min', ['breaker' => []]);

        expect(Profiles::policies('min', 'p.q')['breaker']->describe()['name'])->toBe('p.q')
            ->and(Profiles::within('min'))->toBeNull()
            ->and(Profiles::attempts('min'))->toBeNull()
            ->and(Profiles::policies('internal', 'p.q'))->toBe([]);
    });

    it('rejects an unknown profile', function (): void {
        expect(fn (): array => Profiles::definition('missing'))->toThrow(InvalidProfile::class);
    });
});
