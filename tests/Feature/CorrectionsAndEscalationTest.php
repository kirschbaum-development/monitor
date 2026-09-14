<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Log;
use Kirschbaum\Monitor\Contracts\Correction;
use Kirschbaum\Monitor\Exceptions\InvalidControlPoint;
use Kirschbaum\Monitor\Facades\Monitor;
use Kirschbaum\Monitor\Outcome;
use Tests\Fixtures\CardDeclined;
use TiMacDonald\Log\LogFake;
use Workbench\Monitor\ControlPoints\Escalations\PagePayments;

final class DeclineHandler implements Correction
{
    public function __construct(private readonly Repository $config) {}

    public function __invoke(Throwable $exception, Outcome $outcome): mixed
    {
        return ['declined' => $exception instanceof CardDeclined ? $exception->declineCode : 'unknown', 'app' => $this->config->get('app.name'), 'point' => $outcome->point];
    }
}

beforeEach(fn (): array => PagePayments::$paged = []);

describe('corrections as classes', function (): void {
    it('resolves a Correction class from the container and names it in the description', function (): void {
        $control = Monitor::control('payment.charge')->recover(CardDeclined::class, DeclineHandler::class);

        expect($control->run(fn () => throw new CardDeclined('stolen')))->toMatchArray(['declined' => 'stolen', 'app' => 'Laravel', 'point' => 'payment.charge'])
            ->and($control->describe()['corrections'])->toBe([DeclineHandler::class])
            ->and(Monitor::control('a.b')->recover(CardDeclined::class, fn (): null => null)->describe()['corrections'])->toBe(['closure']);
    });

    it('rejects a class that is not a Correction', function (): void {
        expect(fn () => Monitor::control('payment.charge')->recover(CardDeclined::class, stdClass::class))->toThrow(InvalidControlPoint::class, 'Correction class');
    });
});

describe('escalation on limits', function (): void {
    it('escalates a run that succeeded but breached a limit when asked', function (): void {
        $control = fn () => Monitor::control('payment.charge')->within(0.0001)->escalate(PagePayments::class);

        $control()->run(fn (): null => usleep(500));
        expect(PagePayments::$paged)->toBe([]);

        $outcome = $control()->escalateLimits()->attempt(fn (): null => usleep(500));
        expect($outcome->succeeded())->toBeTrue()
            ->and(PagePayments::$paged)->toHaveCount(1)
            ->and(PagePayments::$paged[0]->breachedLimit('duration'))->toBeTrue()
            ->and($control()->escalateLimits()->describe()['escalate_limits'])->toBeTrue();
    });
});

describe('throttled escalation', function (): void {
    it('escalates at most once per window per point', function (): void {
        LogFake::bind();
        $control = fn () => Monitor::control('payment.charge')->escalate(PagePayments::class)->throttleEscalation(600);

        $control()->attempt(fn () => throw new CardDeclined);
        $control()->attempt(fn () => throw new CardDeclined);
        Monitor::control('payment.refund')->escalate(PagePayments::class)->throttleEscalation(600)->attempt(fn () => throw new CardDeclined);

        expect(PagePayments::$paged)->toHaveCount(2)
            ->and(array_map(fn (Outcome $o): string => $o->point, PagePayments::$paged))->toBe(['payment.charge', 'payment.refund']);
        Log::assertLoggedTimes(fn ($log): bool => ($log->context['event'] ?? null) === 'escalation.throttled' && $log->level === 'notice' && $log->context['throttle_seconds'] === 600 && str_contains($log->message, 'escalation skipped'), 1);

        $this->travel(601)->seconds();
        $control()->attempt(fn () => throw new CardDeclined);

        expect(PagePayments::$paged)->toHaveCount(3)
            ->and($control()->describe()['escalation_throttle'])->toBe(600);
    });
});

describe('origin-less logging and timestamps', function (): void {
    it('binds Monitor::log() to the running point and falls back outside one', function (): void {
        $inside = null;

        Monitor::control('payment.charge', 'App\\Services\\Payments\\Charger')->run(function () use (&$inside): void {
            $inside = Monitor::log()->prefix();
        });

        expect($inside)->toBe('[Payments:Charger]')
            ->and(Monitor::log()->origin())->toBe(Kirschbaum\Monitor\Monitor::class);
    });

    it('records when a run started and ended', function (): void {
        $outcome = Monitor::control('payment.charge')->attempt(fn (): null => usleep(1000));

        expect($outcome->startedAt->lessThanOrEqualTo($outcome->endedAt))->toBeTrue()
            ->and($outcome->toArray())->toHaveKeys(['started_at', 'ended_at']);
    });
});
