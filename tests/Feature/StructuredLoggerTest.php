<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Kirschbaum\Monitor\Facades\Monitor;
use Kirschbaum\Monitor\Logging\StructuredLogger;
use TiMacDonald\Log\LogEntry;
use TiMacDonald\Log\LogFake;

beforeEach(function (): void {
    LogFake::bind();
    config()->set('monitor.domains.map', ['App\\Services\\' => null]);
});

describe('Monitor::log()', function (): void {
    it('prefixes the message and adds origin and domain to the context', function (): void {
        $logger = Monitor::log('App\\Services\\Payments\\StripeCharger');

        expect($logger)->toBeInstanceOf(StructuredLogger::class)
            ->and($logger->origin())->toBe('App\\Services\\Payments\\StripeCharger')
            ->and($logger->domain())->toBe('Payments')
            ->and($logger->prefix())->toBe('[Payments:StripeCharger]');

        $logger->info('charging', ['invoice' => 7]);
        $logger->error('failed');

        Log::assertLogged(fn (LogEntry $log): bool => $log->level === 'info'
            && $log->message === '[Payments:StripeCharger] charging'
            && $log->context === ['invoice' => 7, 'origin' => 'App\\Services\\Payments\\StripeCharger', 'domain' => 'Payments']);
        Log::assertLogged(fn (LogEntry $log): bool => $log->level === 'error' && $log->message === '[Payments:StripeCharger] failed');
    });

    it('accepts an object origin, carries context with with(), and targets a channel', function (): void {
        config()->set('logging.channels.audit', ['driver' => 'single', 'path' => storage_path('logs/audit.log')]);

        $logger = Monitor::log($this)->with(['request' => 'r1'])->channel('audit');

        $logger->warning('careful', ['step' => 2]);
        $logger->log('notice', 'noted');
        $logger->log(new class implements Stringable
        {
            public function __toString(): string
            {
                return 'debug';
            }
        }, 'stringable level');
        $logger->log(42, 'bad level falls back to info');

        Log::channel('audit')->assertLogged(fn (LogEntry $log): bool => $log->level === 'warning' && $log->context['request'] === 'r1' && $log->context['step'] === 2 && $log->context['origin'] === static::class);
        Log::channel('audit')->assertLogged(fn (LogEntry $log): bool => $log->level === 'notice' && str_ends_with($log->message, 'noted'));
        Log::channel('audit')->assertLogged(fn (LogEntry $log): bool => $log->level === 'debug');
        Log::channel('audit')->assertLogged(fn (LogEntry $log): bool => $log->level === 'info' && str_ends_with($log->message, 'bad level falls back to info'));
        Log::assertNothingLogged();
    });
});
