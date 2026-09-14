<?php

declare(strict_types=1);

use Kirschbaum\Monitor\Facades\Monitor;
use Kirschbaum\Monitor\Http\Middleware\CheckBreakers;
use Kirschbaum\Monitor\Http\Middleware\StartTrace;
use Kirschbaum\Monitor\Testing\MonitorFake;

describe('service provider', function (): void {
    it('adds a section to php artisan about', function (): void {
        $this->artisan('about', ['--only' => 'monitor'])
            ->expectsOutputToContain('Monitor')
            ->expectsOutputToContain('Outcome Store')
            ->expectsOutputToContain('external, database, messaging, internal')
            ->assertSuccessful();
    });

    it('aliases the middleware', function (): void {
        $aliases = resolve('router')->getMiddleware();

        expect($aliases['monitor.trace'])->toBe(StartTrace::class)->and($aliases['monitor.breakers'])->toBe(CheckBreakers::class);
    });

    it('returns the same fake when faked twice and reports it', function (): void {
        $first = Monitor::fake();

        expect(Monitor::isFake())->toBeTrue()
            ->and(Monitor::fake())->toBe($first)
            ->and($first)->toBeInstanceOf(MonitorFake::class);
    });
});
