<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Kirschbaum\Monitor\Store\OutcomeStore;

final class OutcomesCommand extends Command
{
    protected $signature = 'monitor:outcomes
        {--point= : Only this control point}
        {--domain= : Only this domain}
        {--status= : succeeded, recovered, escalated or refused}
        {--trace= : Only this trace id}
        {--since=24h : How far back, e.g. 15m, 2h, 7d}
        {--limit=50 : Rows to show}
        {--json : Print JSON instead of a table}';

    protected $description = 'Recent control point outcomes from the store';

    public function handle(OutcomeStore $store): int
    {
        if (! $store->enabled()) {
            $this->components->error('The outcome store is disabled. Set MONITOR_STORE=true and run the migration.');

            return self::FAILURE;
        }

        $since = self::parseSince($this->stringOption('since') ?? '24h');

        if (! $since instanceof Carbon) {
            $this->components->error('--since must look like 15m, 2h or 7d.');

            return self::INVALID;
        }

        $rows = $store->recent([
            'point' => $this->stringOption('point'),
            'domain' => $this->stringOption('domain'),
            'status' => $this->stringOption('status'),
            'trace' => $this->stringOption('trace'),
            'since' => $since,
        ], (int) ($this->stringOption('limit') ?? '50'));

        if ($this->option('json')) {
            $this->line((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->components->info('No outcomes in that window.');

            return self::SUCCESS;
        }

        $this->table(
            ['Ended', 'Point', 'Domain', 'Status', 'Attempts', 'ms', 'Exception', 'Trace'],
            array_map(fn (array $row): array => [
                is_string($row['ended_at']) ? $row['ended_at'] : '',
                $row['point'],
                $row['domain'],
                $row['status'],
                $row['attempts'],
                $row['duration_ms'],
                $row['exception_class'] ?? '',
                is_string($row['trace_id']) ? substr($row['trace_id'], 0, 8).'…' : '',
            ], $rows),
        );

        return self::SUCCESS;
    }

    public static function parseSince(string $value): ?Carbon
    {
        if (preg_match('/^(\d+)([mhd])$/', trim($value), $m) !== 1) {
            return null;
        }

        $amount = (int) $m[1];

        return match ($m[2]) {
            'm' => Date::now()->subMinutes($amount),
            'h' => Date::now()->subHours($amount),
            default => Date::now()->subDays($amount),
        };
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
