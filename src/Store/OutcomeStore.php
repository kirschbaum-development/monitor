<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Store;

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Kirschbaum\Monitor\Outcome;
use Kirschbaum\Monitor\Records\ExceptionSummary;
use Kirschbaum\Monitor\Support\Redaction;

/**
 * Outcomes in a table, so they can be queried without a log backend.
 *
 * Off by default. Rows are written by StoreOutcomes after the response or job,
 * never on the request path, and a failing write never reaches a control point.
 */
class OutcomeStore
{
    public function __construct(private readonly ConnectionResolverInterface $db) {}

    public function enabled(): bool
    {
        return Config::boolean('monitor.records.store.enabled', false);
    }

    public function table(): string
    {
        return Config::string('monitor.records.store.table', 'monitor_outcomes');
    }

    public function connection(): ?string
    {
        $connection = Config::get('monitor.records.store.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    public function retentionDays(): int
    {
        return max(1, Config::integer('monitor.records.store.retention_days', 30));
    }

    /**
     * @param  list<Outcome>  $outcomes
     */
    public function write(array $outcomes): int
    {
        if ($outcomes === []) {
            return 0;
        }

        $rows = array_map($this->row(...), $outcomes);

        $this->query()->upsert($rows, ['run_id'], ['status', 'attempts', 'duration_ms', 'ended_at']);

        return count($rows);
    }

    /**
     * A query over stored outcomes, newest first.
     */
    public function query(): Builder
    {
        return $this->db->connection($this->connection())->table($this->table());
    }

    /**
     * @param  array{point?: string|null, domain?: string|null, status?: string|null, since?: \DateTimeInterface|null, trace?: string|null}  $filters
     * @return list<array<string, mixed>>
     */
    public function recent(array $filters = [], int $limit = 50): array
    {
        $query = $this->query()->latest('ended_at')->orderByDesc('id')->limit(max(1, $limit));

        if (! empty($filters['point'])) {
            $query->where('point', $filters['point']);
        }

        if (! empty($filters['domain'])) {
            $query->where('domain', $filters['domain']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['trace'])) {
            $query->where('trace_id', $filters['trace']);
        }

        if (isset($filters['since'])) {
            $query->where('ended_at', '>=', Date::instance($filters['since']));
        }

        return array_values(array_map($this->hydrate(...), $query->get()->all()));
    }

    /**
     * Counts per status for one point or for all, since a moment.
     *
     * @return array<string, int>
     */
    public function tally(?string $point = null, ?\DateTimeInterface $since = null): array
    {
        $query = $this->query()->selectRaw('status, count(*) as total')->groupBy('status');

        if ($point !== null) {
            $query->where('point', $point);
        }

        if ($since instanceof \DateTimeInterface) {
            $query->where('ended_at', '>=', Date::instance($since));
        }

        $tally = [];

        foreach ($query->get() as $row) {
            $status = is_string($row->status) ? $row->status : 'unknown';
            $tally[$status] = is_numeric($row->total) ? (int) $row->total : 0;
        }

        return $tally;
    }

    /**
     * When each point last ran and how it ended.
     *
     * @return array<string, array{ended_at: string, status: string}>
     */
    public function lastSeen(): array
    {
        $rows = $this->query()->selectRaw('point, max(ended_at) as last_at')->groupBy('point')->get();
        $seen = [];

        foreach ($rows as $row) {
            $point = is_string($row->point) ? $row->point : '';
            $lastAt = is_string($row->last_at) ? $row->last_at : '';
            $last = $this->query()->where('point', $point)->where('ended_at', $lastAt)->orderByDesc('id')->first();

            $seen[$point] = [
                'ended_at' => $lastAt,
                'status' => is_object($last) && is_string($last->status) ? $last->status : 'unknown',
            ];
        }

        return $seen;
    }

    public function prune(?int $days = null): int
    {
        $cutoff = Date::now()->subDays($days ?? $this->retentionDays());

        return $this->query()->where('ended_at', '<', $cutoff)->delete();
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(Outcome $outcome): array
    {
        $exception = $outcome->exception instanceof \Throwable ? Redaction::exception(ExceptionSummary::from($outcome->exception)) : null;

        return [
            'run_id' => $outcome->id,
            'parent_run_id' => $outcome->parentId,
            'trace_id' => $outcome->traceId,
            'point' => $outcome->point,
            'domain' => $outcome->domain,
            'origin' => $outcome->origin,
            'profile' => $outcome->profile,
            'status' => $outcome->status->value,
            'recovered_from' => $outcome->recoveredFrom,
            'exception_class' => is_array($exception) && is_string($exception['class'] ?? null) ? $exception['class'] : null,
            'exception_message' => is_array($exception) && is_string($exception['message'] ?? null) ? $exception['message'] : null,
            'attempts' => $outcome->attempts,
            'duration_ms' => $outcome->durationMs,
            'stack' => json_encode($outcome->stack),
            'context' => json_encode(Redaction::context($outcome->context)),
            'limits_breached' => json_encode($outcome->limitsBreached),
            'policies' => json_encode($outcome->policies),
            'timeline' => json_encode($outcome->timeline),
            'started_at' => $outcome->startedAt,
            'ended_at' => $outcome->endedAt,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function hydrate(object $row): array
    {
        $data = [];

        foreach (get_object_vars($row) as $key => $value) {
            $data[(string) $key] = $value;
        }

        foreach (['stack', 'context', 'limits_breached', 'policies', 'timeline'] as $json) {
            $data[$json] = is_string($data[$json] ?? null) ? json_decode($data[$json], true) : ($data[$json] ?? null);
        }

        return $data;
    }
}
