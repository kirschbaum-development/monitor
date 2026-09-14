<?php

declare(strict_types=1);

use Illuminate\Log\Logger;
use Kirschbaum\Monitor\Logging\JsonTap;
use Kirschbaum\Monitor\Logging\RecordFormatter;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger as Monolog;
use Monolog\LogRecord;
use Psr\Log\NullLogger;

describe('record formatter', function (): void {
    it('lifts context and extra to the top level with the envelope first', function (): void {
        $record = new LogRecord(new DateTimeImmutable('2026-09-14T18:10:24.118+00:00'), 'stack', Level::Warning, '[Payments:X] payment.charge recovered', [
            'event' => 'point.recovered', 'point' => 'payment.charge', 'trace_id' => 'abc',
        ], ['trace_id' => 'from-extra', 'control_point' => 'payment.charge']);

        $line = json_decode((new RecordFormatter)->format($record), true);

        expect(array_keys($line))->toBe(['ts', 'level', 'channel', 'message', 'trace_id', 'control_point', 'event', 'point'])
            ->and($line['ts'])->toBe('2026-09-14T18:10:24.118+00:00')
            ->and($line['level'])->toBe('warning')
            ->and($line['trace_id'])->toBe('abc');
    });

    it('never loses a line to bad bytes', function (): void {
        $record = new LogRecord(new DateTimeImmutable, 'app', Level::Info, "bad \xB1\x31 bytes", ['raw' => "\xB1"], []);

        $out = (new RecordFormatter)->format($record);

        expect($out)->toEndWith("\n")->and(json_decode($out, true))->toBeArray()->and(json_decode($out, true)['message'])->toContain('bad');
    });

    it('is installed on every formattable handler by the tap', function (): void {
        $handler = new TestHandler;
        $logger = new Logger(new Monolog('t', [$handler]));

        (new JsonTap)($logger);

        expect($handler->getFormatter())->toBeInstanceOf(RecordFormatter::class);
    });

    it('ignores a channel that is not a Monolog logger', function (): void {
        $logger = new Logger(new NullLogger);

        (new JsonTap)($logger);

        expect(true)->toBeTrue();
    });
});
