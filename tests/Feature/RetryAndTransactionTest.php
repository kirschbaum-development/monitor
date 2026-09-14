<?php

declare(strict_types=1);

use Illuminate\Database\DeadlockException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Kirschbaum\Monitor\Events\PointRetried;
use Kirschbaum\Monitor\Facades\Monitor;
use Kirschbaum\Monitor\Policies\Retry;
use Kirschbaum\Monitor\Status;
use Tests\Fixtures\Fatal;
use Tests\Fixtures\Flaky;

beforeEach(function (): void {
    Sleep::fake();
});

describe('retry', function (): void {
    it('retries and reports the total attempts', function (): void {
        Event::fake([PointRetried::class]);

        $outcome = Monitor::control('search.index')->retry(times: 2, backoffMs: 100, jitter: false)->attempt(failingTimes(2));

        expect($outcome->status)->toBe(Status::Succeeded)
            ->and($outcome->value)->toBe('ok after 3')
            ->and($outcome->attempts)->toBe(3);

        Event::assertDispatchedTimes(PointRetried::class, 2);
        Event::assertDispatched(PointRetried::class, fn (PointRetried $e): bool => $e->attempt === 1 && $e->backoffMs === 100);
        Event::assertDispatched(PointRetried::class, fn (PointRetried $e): bool => $e->attempt === 2 && $e->backoffMs === 200);
        Sleep::assertSleptTimes(2);
    });

    it('rethrows the original exception when retries are exhausted', function (): void {
        $outcome = Monitor::control('search.index')->retry(times: 1, backoffMs: 0)->attempt(failingTimes(5));

        expect($outcome->status)->toBe(Status::Escalated)
            ->and($outcome->exception)->toBeInstanceOf(Flaky::class)
            ->and($outcome->attempts)->toBe(2);
    });

    it('only retries the exceptions it is told to', function (): void {
        $outcome = Monitor::control('search.index')->retry(times: 3, backoffMs: 0, on: [Flaky::class])->attempt(failingTimes(1, new Fatal('fatal')));

        expect($outcome->attempts)->toBe(1)->and($outcome->exception)->toBeInstanceOf(Fatal::class);
    });

    it('never retries an excepted exception', function (): void {
        $outcome = Monitor::control('search.index')->retry(times: 3, backoffMs: 0, except: [Fatal::class])->attempt(failingTimes(1, new Fatal('fatal')));

        expect($outcome->attempts)->toBe(1);
    });

    it('caps attempts with the attempts() limit whatever the policy asks', function (): void {
        $outcome = Monitor::control('search.index')->retry(times: 10, backoffMs: 0)->attempts(2)->attempt(failingTimes(5));

        expect($outcome->attempts)->toBe(2)
            ->and($outcome->breachedLimit('attempts'))->toBeTrue()
            ->and($outcome->limitsBreached['attempts'])->toBe(['threshold' => 2, 'actual' => 2]);
    });

    it('adds jitter between half and the full delay', function (): void {
        $delays = [];
        Sleep::whenFakingSleep(function ($duration) use (&$delays): void {
            $delays[] = $duration->totalMilliseconds;
        });

        Monitor::control('search.index')->retry(times: 3, backoffMs: 100, multiplier: 1.0, jitter: true)->attempt(failingTimes(3));

        expect($delays)->toHaveCount(3);

        foreach ($delays as $delay) {
            expect($delay)->toBeGreaterThanOrEqual(50)->toBeLessThanOrEqual(100);
        }
    });

    it('accepts a policy object directly and replaces the same type', function (): void {
        $outcome = Monitor::control('search.index')->retry(times: 0)->policy(Retry::times(2))->attempt(failingTimes(2));

        expect($outcome->attempts)->toBe(3)->and($outcome->status)->toBe(Status::Succeeded);
    });

    it('does not retry a corrected ensure failure', function (): void {
        $calls = 0;

        $outcome = Monitor::control('search.index')->retry(times: 3, backoffMs: 0)
            ->ensure(fn (): bool => false, 'never')
            ->attempt(function () use (&$calls): int {
                return ++$calls;
            });

        expect($calls)->toBe(1)->and($outcome->attempts)->toBe(1)->and($outcome->escalated())->toBeTrue();
    });
});

describe('transaction', function (): void {
    beforeEach(function (): void {
        Schema::create('ledger', function ($table): void {
            $table->id();
            $table->string('entry');
        });
    });

    it('commits on success', function (): void {
        Monitor::control('ledger.post')->transaction()->run(fn () => DB::table('ledger')->insert(['entry' => 'a']));

        expect(DB::table('ledger')->count())->toBe(1);
    });

    it('rolls back on failure and rethrows', function (): void {
        $outcome = Monitor::control('ledger.post')->transaction()->attempt(function (): void {
            DB::table('ledger')->insert(['entry' => 'a']);

            throw new Fatal('no');
        });

        expect($outcome->escalated())->toBeTrue()->and(DB::table('ledger')->count())->toBe(0);
    });

    it('retries deadlocks as whole transactions and counts every attempt', function (): void {
        $outcome = Monitor::control('ledger.post')->transaction(retries: 2)->attempt(function (): string {
            DB::table('ledger')->insert(['entry' => 'try']);

            if (DB::table('ledger')->count() > 0 && DB::table('ledger')->count() < 3) {
                // Each attempt is rolled back, so the count never reaches 3;
                // count the real attempts through the outcome instead.
            }

            static $tries = 0;
            $tries++;

            if ($tries < 3) {
                throw new DeadlockException('deadlock');
            }

            return 'posted';
        });

        expect($outcome->status)->toBe(Status::Succeeded)
            ->and($outcome->attempts)->toBe(3)
            ->and(DB::table('ledger')->count())->toBe(1);
    });

    it('does not retry a non-deadlock failure inside a transaction', function (): void {
        $outcome = Monitor::control('ledger.post')->transaction(retries: 2)->attempt(failingTimes(1, new Fatal('x')));

        expect($outcome->attempts)->toBe(1);
    });

    it('runs the transaction inside the retry policy, so each retry gets a fresh transaction', function (): void {
        $control = Monitor::control('ledger.post')->transaction(retries: 0)->retry(times: 1, backoffMs: 0);
        $types = array_map(fn (array $p): string => $p['type'], $control->describe()['policies']);

        expect($types)->toBe(['retry', 'transaction']);
    });
});
