<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Records;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Kirschbaum\Monitor\Events\BreakerClosed;
use Kirschbaum\Monitor\Events\BreakerHalfOpen;
use Kirschbaum\Monitor\Events\BreakerOpened;
use Kirschbaum\Monitor\Events\EscalationFailed;
use Kirschbaum\Monitor\Events\PointEnded;
use Kirschbaum\Monitor\Events\PointEscalated;
use Kirschbaum\Monitor\Events\PointLimitBreached;
use Kirschbaum\Monitor\Events\PointRecovered;
use Kirschbaum\Monitor\Events\PointRefused;
use Kirschbaum\Monitor\Events\PointRetried;
use Kirschbaum\Monitor\Events\PointStarted;
use Kirschbaum\Monitor\Support\Redaction;
use Psr\Log\LoggerInterface;

/**
 * Writes one log record per transition, on the configured channel, with the
 * context redacted. Levels come from config('monitor.records.levels').
 */
class Recorder
{
    /**
     * @return array<class-string, string>
     */
    public function subscribe(): array
    {
        return [
            PointStarted::class => 'started',
            PointRetried::class => 'retried',
            PointLimitBreached::class => 'limit',
            PointRecovered::class => 'recovered',
            PointEscalated::class => 'escalated',
            PointRefused::class => 'refused',
            PointEnded::class => 'ended',
            EscalationFailed::class => 'escalationFailed',
            BreakerOpened::class => 'breakerOpened',
            BreakerHalfOpen::class => 'breakerHalfOpen',
            BreakerClosed::class => 'breakerClosed',
        ];
    }

    public function started(PointStarted $event): void
    {
        $this->write(Record::started($event->run));
    }

    public function retried(PointRetried $event): void
    {
        $this->write(Record::retried($event));
    }

    public function limit(PointLimitBreached $event): void
    {
        $this->write(Record::limit($event));
    }

    public function recovered(PointRecovered $event): void
    {
        $this->write(Record::recovered($event->outcome));
    }

    public function escalated(PointEscalated $event): void
    {
        $this->write(Record::escalated($event->outcome));
    }

    public function refused(PointRefused $event): void
    {
        $this->write(Record::refused($event->outcome));
    }

    public function ended(PointEnded $event): void
    {
        $this->write(Record::ended($event->outcome));
    }

    public function escalationFailed(EscalationFailed $event): void
    {
        $this->write(Record::escalationFailed($event));
    }

    public function breakerOpened(BreakerOpened $event): void
    {
        $this->write(Record::breaker('breaker.opened', $event->breaker, $event->state));
    }

    public function breakerHalfOpen(BreakerHalfOpen $event): void
    {
        $this->write(Record::breaker('breaker.half_open', $event->breaker, $event->state));
    }

    public function breakerClosed(BreakerClosed $event): void
    {
        $this->write(Record::breaker('breaker.closed', $event->breaker, $event->state));
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function write(array $record): void
    {
        $event = is_string($record['event'] ?? null) ? $record['event'] : 'point.ended';
        $message = is_string($record['message'] ?? null) ? $record['message'] : $event;
        unset($record['message']);

        $this->logger()->log(self::level($event), $message, Redaction::record($record));
    }

    public static function level(string $event): string
    {
        // Event names contain dots, so the map is read whole rather than by key path.
        $levels = Config::array('monitor.records.levels', []);
        $level = $levels[$event] ?? null;

        return is_string($level) && $level !== '' ? $level : 'info';
    }

    private function logger(): LoggerInterface
    {
        $channel = Config::get('monitor.records.channel');

        return Log::channel(is_string($channel) && $channel !== '' ? $channel : null);
    }
}
