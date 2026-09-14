<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Response;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Sleep;
use Kirschbaum\Monitor\Console\Commands\OutcomesCommand;
use Kirschbaum\Monitor\Facades\Monitor;
use Kirschbaum\Monitor\Store\OutcomeStore;
use Kirschbaum\Monitor\Store\StoreOutcomes;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\Fixtures\CardDeclined;
use Tests\Fixtures\Fatal;
use TiMacDonald\Log\LogFake;

function enableStore(): void
{
    config()->set('monitor.records.store.enabled', true);
    (require glob(__DIR__.'/../../database/migrations/*_create_monitor_outcomes_table.php')[0])->up();
}

describe('outcome store', function (): void {
    it('does nothing while disabled', function (): void {
        Monitor::control('payment.charge')->run(fn (): int => 1);

        expect(resolve(StoreOutcomes::class)->pending())->toBe(0)->and(resolve(OutcomeStore::class)->enabled())->toBeFalse();
    });

    it('buffers outcomes and writes them when the application terminates', function (): void {
        enableStore();
        Sleep::fake();

        Monitor::control('payment.charge', 'App\\Services\\Payments\\Charger')->with(['invoice' => 1, 'password' => 'x'])->run(fn (): int => 1);
        Monitor::control('payment.charge')->retry(times: 1, backoffMs: 0)->recover(CardDeclined::class, fn (): null => null)->run(fn () => throw new CardDeclined('lost'));
        Monitor::control('order.place')->attempt(fn (): string => Monitor::control('payment.charge')->run(fn () => throw new Fatal('secret token Xk9vB2mQ7pL4sN8wR1tY6uZ3aC5dF0gHjE2bV')));

        expect(resolve(StoreOutcomes::class)->pending())->toBe(4)->and(DB::table('monitor_outcomes')->count())->toBe(0);

        resolve(StoreOutcomes::class)->flush();

        expect(resolve(StoreOutcomes::class)->pending())->toBe(0)->and(DB::table('monitor_outcomes')->count())->toBe(4);

        $rows = resolve(OutcomeStore::class)->recent();
        $first = array_values(array_filter($rows, fn (array $r): bool => $r['status'] === 'succeeded'))[0];
        $recovered = array_values(array_filter($rows, fn (array $r): bool => $r['status'] === 'recovered'))[0];
        $child = array_values(array_filter($rows, fn (array $r): bool => $r['point'] === 'payment.charge' && $r['status'] === 'escalated'))[0];
        $parent = array_values(array_filter($rows, fn (array $r): bool => $r['point'] === 'order.place'))[0];

        expect($first)->toMatchArray(['point' => 'payment.charge', 'domain' => 'Payments', 'attempts' => 1])
            ->and($first['context']['invoice'])->toBe(1)
            ->and($first['context']['password'])->toBe('[REDACTED]')
            ->and($first['stack'])->toBe(['payment.charge'])
            ->and($recovered['recovered_from'])->toBe(CardDeclined::class)
            ->and($recovered['attempts'])->toBe(2)
            ->and($recovered['exception_class'])->toBe(CardDeclined::class)
            ->and($child['parent_run_id'])->toBe($parent['run_id'])
            ->and($child['exception_message'])->not->toContain('Xk9vB2mQ7pL4sN8wR1tY6uZ3aC5dF0gHjE2bV')
            ->and($parent['status'])->toBe('escalated');
    });

    it('writes when the buffer limit is reached', function (): void {
        enableStore();

        for ($i = 0; $i < StoreOutcomes::BUFFER_LIMIT; $i++) {
            Monitor::control('payment.charge')->run(fn (): int => 1);
        }

        expect(resolve(StoreOutcomes::class)->pending())->toBe(0)->and(DB::table('monitor_outcomes')->count())->toBe(StoreOutcomes::BUFFER_LIMIT);
    });

    it('flushes at the end of a request and a command', function (): void {
        enableStore();
        Route::get('/charge', fn (): int => Monitor::control('payment.charge')->run(fn (): int => 1));

        $this->get('/charge')->assertOk();
        resolve(Kernel::class)->terminate(request(), new Response);

        expect(DB::table('monitor_outcomes')->count())->toBe(1);

        Monitor::control('payment.charge')->run(fn (): int => 1);
        resolve('events')->dispatch(new Looping('sync', 'default'));

        expect(DB::table('monitor_outcomes')->count())->toBe(2);

        $console = resolve(Illuminate\Contracts\Console\Kernel::class);
        $input = new ArrayInput(['command' => 'list']);
        $console->handle($input, new NullOutput);
        Monitor::control('payment.charge')->run(fn (): int => 1);
        $console->terminate($input, 0);

        expect(DB::table('monitor_outcomes')->count())->toBe(3);
    });

    it('flushes after a queued job finishes', function (): void {
        enableStore();

        Monitor::control('payment.charge')->run(fn (): int => 1);
        resolve('events')->dispatch(new JobProcessed('sync', Mockery::mock(Job::class)));

        expect(DB::table('monitor_outcomes')->count())->toBe(1);
    });

    it('never lets a failing write reach the control point, and reports once', function (): void {
        config()->set('monitor.records.store.enabled', true);
        LogFake::bind();

        expect(Monitor::control('payment.charge')->run(fn (): string => 'still fine'))->toBe('still fine');
        expect(resolve(StoreOutcomes::class)->flush())->toBe(0);

        Monitor::control('payment.charge')->run(fn (): int => 1);
        expect(resolve(StoreOutcomes::class)->flush())->toBe(0);

        Log::assertLoggedTimes(fn ($log): bool => str_contains($log->message, 'outcome store could not be written'), 1);
    });

    it('filters, tallies, reports last seen and prunes', function (): void {
        enableStore();

        Monitor::control('payment.charge')->run(fn (): int => 1);
        Monitor::control('payment.charge')->attempt(fn () => throw new Fatal);
        Monitor::control('search.index', 'App\\Services\\Search\\Indexer')->run(fn (): int => 1);
        resolve(StoreOutcomes::class)->flush();

        $store = resolve(OutcomeStore::class);

        expect($store->recent(['point' => 'payment.charge']))->toHaveCount(2)
            ->and($store->recent(['status' => 'escalated']))->toHaveCount(1)
            ->and($store->recent(['domain' => 'Search']))->toHaveCount(1)
            ->and($store->recent(['trace' => Monitor::trace()->id()]))->toHaveCount(3)
            ->and($store->recent([], 1))->toHaveCount(1)
            ->and($store->recent(['since' => now()->addMinute()]))->toHaveCount(0)
            ->and($store->tally())->toBe(['escalated' => 1, 'succeeded' => 2])
            ->and($store->tally('search.index'))->toBe(['succeeded' => 1])
            ->and($store->tally('payment.charge', now()->addMinute()))->toBe([])
            ->and(array_keys($store->lastSeen()))->toBe(['payment.charge', 'search.index'])
            ->and($store->lastSeen()['payment.charge']['status'])->toBe('escalated');

        $this->travel(31)->days();
        Monitor::control('payment.charge')->run(fn (): int => 1);
        resolve(StoreOutcomes::class)->flush();

        expect($store->prune())->toBe(3)->and(DB::table('monitor_outcomes')->count())->toBe(1);
    });

    it('does not buffer while disabled even if the listener is bound', function (): void {
        Monitor::control('payment.charge')->run(fn (): int => 1);

        expect(resolve(StoreOutcomes::class)->flush())->toBe(0);
    });
});

describe('commands', function (): void {
    it('refuses monitor:outcomes while the store is disabled', function (): void {
        $this->artisan('monitor:outcomes')->assertFailed();
    });

    it('lists outcomes as a table and as json', function (): void {
        enableStore();
        Monitor::control('payment.charge')->attempt(fn () => throw new Fatal('bad'));
        resolve(StoreOutcomes::class)->flush();

        $this->artisan('monitor:outcomes')->expectsOutputToContain('payment.charge')->assertSuccessful();
        $this->artisan('monitor:outcomes', ['--json' => true, '--status' => 'escalated'])->expectsOutputToContain('"status": "escalated"')->assertSuccessful();
        $this->artisan('monitor:outcomes', ['--json' => true])->expectsOutputToContain('Fixtures')->assertSuccessful();
        $this->artisan('monitor:outcomes', ['--since' => '1m', '--point' => 'nope.nope'])->expectsOutputToContain('No outcomes')->assertSuccessful();
        $this->artisan('monitor:outcomes', ['--since' => 'yesterday'])->assertExitCode(2);
    });

    it('parses since windows', function (): void {
        expect(OutcomesCommand::parseSince('15m')?->diffInMinutes(now()))->toBeGreaterThanOrEqual(14)
            ->and(OutcomesCommand::parseSince('2h')?->diffInHours(now()))->toBeGreaterThanOrEqual(1)
            ->and(OutcomesCommand::parseSince('7d')?->diffInDays(now()))->toBeGreaterThanOrEqual(6)
            ->and(OutcomesCommand::parseSince('x'))->toBeNull();
    });

    it('prunes from the command', function (): void {
        $this->artisan('monitor:prune')->expectsOutputToContain('disabled')->assertSuccessful();

        enableStore();
        Monitor::control('payment.charge')->run(fn (): int => 1);
        resolve(StoreOutcomes::class)->flush();
        $this->travel(2)->days();

        $this->artisan('monitor:prune', ['--days' => 1])->expectsOutputToContain('Pruned 1')->assertSuccessful();
    });
});
