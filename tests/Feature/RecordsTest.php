<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Kirschbaum\Monitor\Facades\Monitor;
use Kirschbaum\Monitor\Records\ExceptionSummary;
use Kirschbaum\Monitor\Records\Record;
use Kirschbaum\Monitor\Records\Recorder;
use Kirschbaum\Monitor\Support\Redaction;
use Tests\Fixtures\BrokenEscalation;
use Tests\Fixtures\CardDeclined;
use Tests\Fixtures\Fatal;
use TiMacDonald\Log\LogEntry;
use TiMacDonald\Log\LogFake;

beforeEach(function (): void {
    LogFake::bind();
});

function recordsFor(string $event, ?string $channel = null): array
{
    return Log::channel($channel)->logs()->filter(fn (LogEntry $log): bool => ($log->context['event'] ?? null) === $event)->values()->all();
}

describe('recorder', function (): void {
    it('writes started and ended for a success, with the schema fields', function (): void {
        Monitor::control('payment.charge', 'App\\Services\\Payments\\StripeCharger')->with(['invoice' => 7])->run(fn (): string => 'ok');

        $started = recordsFor('point.started');
        $ended = recordsFor('point.ended');

        expect($started)->toHaveCount(1)->and($ended)->toHaveCount(1);

        $s = $started[0];
        expect($s->level)->toBe('debug')
            ->and($s->message)->toBe('[Payments:StripeCharger] payment.charge started')
            ->and($s->context)->toMatchArray([
                'schema' => Record::SCHEMA,
                'event' => 'point.started',
                'point' => 'payment.charge',
                'domain' => 'Payments',
                'origin' => 'App\\Services\\Payments\\StripeCharger',
                'stack' => ['payment.charge'],
                'attempt' => 1,
                'context' => ['invoice' => 7],
            ])
            ->and($s->context['run_id'])->toHaveLength(26)
            ->and($s->context['trace_id'])->toMatch('/^[0-9a-f]{32}$/');

        $e = $ended[0];
        expect($e->level)->toBe('info')
            ->and($e->message)->toMatch('/^\[Payments:StripeCharger\] payment\.charge succeeded in [0-9.]+ms$/')
            ->and($e->context['status'])->toBe('succeeded')
            ->and($e->context['attempts'])->toBe(1)
            ->and($e->context)->not->toHaveKey('exception');
    });

    it('records recovery with the risk and the summarised exception', function (): void {
        Monitor::control('payment.charge')->recover(CardDeclined::class, fn (): string => 'declined')->run(fn () => throw new CardDeclined('stolen'));

        $r = recordsFor('point.recovered')[0];

        expect($r->level)->toBe('warning')
            ->and($r->message)->toContain('recovered from '.CardDeclined::class)
            ->and($r->context['risk'])->toBe(CardDeclined::class)
            ->and($r->context['exception'])->toMatchArray(['class' => CardDeclined::class, 'message' => 'Card declined: stolen'])
            ->and($r->context['exception'])->not->toHaveKey('trace')
            ->and(recordsFor('point.ended')[0]->context['status'])->toBe('recovered');
    });

    it('records escalation, a failing escalation handler, retries and limits', function (): void {
        Sleep::fake();

        Monitor::control('payment.charge')->retry(times: 1, backoffMs: 5, jitter: false)->within(0.00001)->escalate(BrokenEscalation::class)
            ->attempt(fn () => throw new Fatal('boom'));

        $retried = recordsFor('point.retried')[0];
        $limit = recordsFor('point.limit')[0];
        $escalated = recordsFor('point.escalated')[0];
        $failed = recordsFor('escalation.failed')[0];

        expect($retried->level)->toBe('notice')
            ->and($retried->context)->toMatchArray(['attempt' => 1, 'backoff_ms' => 5])
            ->and($retried->message)->toContain('attempt 1 failed with '.Fatal::class.', retrying after 5ms')
            ->and($limit->level)->toBe('warning')
            ->and($limit->context['limit']['name'])->toBe('duration')
            ->and($limit->message)->toContain('breached the duration limit')
            ->and($escalated->level)->toBe('error')
            ->and($escalated->message)->toContain('escalated '.Fatal::class.' after 2 attempt(s)')
            ->and($escalated->context['status'])->toBe('escalated')
            ->and($failed->level)->toBe('critical')
            ->and($failed->context['escalation_exception']['class'])->toBe(LogicException::class)
            ->and($failed->message)->toContain('escalation handler threw');
    });

    it('records refusals and breaker transitions', function (): void {
        Monitor::breaker()->open('stripe', 30);
        Monitor::control('payment.charge')->breaker('stripe')->attempt(fn (): int => 1);
        Monitor::breaker()->close('stripe');

        $refused = recordsFor('point.refused')[0];
        $opened = recordsFor('breaker.opened')[0];
        $closed = recordsFor('breaker.closed')[0];

        expect($refused->level)->toBe('warning')
            ->and($refused->context['breaker'])->toBe(['name' => 'stripe', 'state' => 'open', 'retry_after_s' => 30])
            ->and($refused->message)->toContain('refused: breaker stripe is open')
            ->and($opened->level)->toBe('error')
            ->and($opened->context['breaker'])->toMatchArray(['name' => 'stripe', 'state' => 'open', 'open_for' => 30])
            ->and($closed->message)->toBe('[Monitor] breaker stripe is now closed');
    });

    it('redacts context and exception messages but leaves identifiers alone', function (): void {
        Monitor::control('payment.charge')->with(['email' => 'bob@example.com', 'invoice' => 42, 'password' => 'hunter2'])
            ->attempt(fn () => throw new Fatal('card 4111 1111 1111 1111 failed for bob@example.com'));

        $ended = recordsFor('point.ended')[0];

        expect($ended->context['context']['invoice'])->toBe(42)
            ->and($ended->context['context']['password'])->toBe('[REDACTED]')
            ->and($ended->context['context']['email'])->not->toBe('bob@example.com')
            ->and($ended->context['context'])->not->toHaveKey('_redacted')
            ->and($ended->context['exception']['message'])->not->toContain('4111 1111 1111 1111')
            ->and($ended->context['run_id'])->toHaveLength(26)
            ->and($ended->context['trace_id'])->toMatch('/^[0-9a-f]{32}$/');
    });

    it('can turn redaction off and honours the configured channel and levels', function (): void {
        config()->set('monitor.records.redaction');
        config()->set('monitor.records.channel', 'audit');
        config()->set('monitor.records.levels', ['point.ended' => 'notice'] + config('monitor.records.levels'));
        config()->set('logging.channels.audit', ['driver' => 'single', 'path' => storage_path('logs/audit.log')]);

        Monitor::control('payment.charge')->with(['password' => 'hunter2'])->run(fn (): int => 1);

        $ended = recordsFor('point.ended', 'audit')[0] ?? null;

        expect($ended)->not->toBeNull()
            ->and($ended->level)->toBe('notice')
            ->and($ended->context['context']['password'])->toBe('hunter2')
            ->and(Recorder::level('unknown.event'))->toBe('info');
    });
});

describe('exception summary', function (): void {
    it('summarises without a trace by default and includes previous', function (): void {
        $summary = ExceptionSummary::from(new Fatal('outer', 7, new CardDeclined));

        expect($summary)->toMatchArray(['class' => Fatal::class, 'message' => 'outer', 'code' => 7])
            ->and($summary['line'])->toBeInt()
            ->and($summary['previous'])->toBe(['class' => CardDeclined::class, 'message' => 'Card declined: do_not_honor'])
            ->and($summary)->not->toHaveKey('trace');
    });

    it('includes a capped trace when configured always, or in debug', function (): void {
        config()->set('monitor.records.exception_trace', 'always');
        config()->set('monitor.records.exception_trace_lines', 2);

        $summary = ExceptionSummary::from(new Fatal('x'));

        expect($summary['trace'])->toHaveCount(2)->and($summary['trace_truncated'])->toBeGreaterThan(0);

        config()->set('monitor.records.exception_trace', 'debug');
        config()->set('app.debug', false);
        expect(ExceptionSummary::from(new Fatal('x')))->not->toHaveKey('trace');

        config()->set('app.debug', true);
        expect(ExceptionSummary::from(new Fatal('x')))->toHaveKey('trace');
    });
});

describe('redaction helper', function (): void {
    it('passes records through untouched when no profile is configured', function (): void {
        config()->set('monitor.records.redaction', '');

        $record = ['context' => ['password' => 'x'], 'exception' => ['message' => 'secret']];

        expect(Redaction::record($record))->toBe($record)->and(Redaction::profile())->toBeNull();
    });
});
