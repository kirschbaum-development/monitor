<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor;

use Illuminate\Support\Facades\Context;

/**
 * The control points currently executing, outermost first.
 *
 * Kept in Laravel's Context: the full stack hidden, the current point name
 * visible as "control_point" so it lands on every log line the application
 * writes while the point runs.
 */
class ControlStack
{
    public const string KEY = 'monitor.stack';

    public const string CURRENT = 'control_point';

    public const string DISPATCHED_FROM = 'dispatched_from_run';

    public function push(string $point, string $runId, ?string $origin = null): void
    {
        $stack = $this->all();
        $stack[] = ['point' => $point, 'run_id' => $runId, 'origin' => $origin];

        Context::addHidden(self::KEY, $stack);
        Context::add(self::CURRENT, $point);
    }

    public function pop(): void
    {
        $stack = $this->all();
        array_pop($stack);

        if ($stack === []) {
            Context::forgetHidden(self::KEY);
            Context::forget(self::CURRENT);

            return;
        }

        Context::addHidden(self::KEY, $stack);
        Context::add(self::CURRENT, $stack[array_key_last($stack)]['point']);
    }

    /**
     * @return array{point: string, run_id: string, origin: string|null}|null
     */
    public function current(): ?array
    {
        $stack = $this->all();

        return $stack === [] ? null : $stack[array_key_last($stack)];
    }

    public function currentRunId(): ?string
    {
        return $this->current()['run_id'] ?? null;
    }

    public function depth(): int
    {
        return count($this->all());
    }

    public function isInside(): bool
    {
        return $this->all() !== [];
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(fn (array $entry): string => $entry['point'], $this->all());
    }

    /**
     * The origin class of the innermost running point, if any.
     */
    public function currentOrigin(): ?string
    {
        return $this->current()['origin'] ?? null;
    }

    /**
     * Forget the stack but remember which run dispatched this process's work,
     * for a job that starts with a stack it inherited through Context.
     */
    public function handOff(): void
    {
        $current = $this->currentRunId();

        $this->clear();

        if ($current !== null) {
            Context::add(self::DISPATCHED_FROM, $current);
        }
    }

    public function dispatchedFrom(): ?string
    {
        $id = Context::get(self::DISPATCHED_FROM);

        return is_string($id) ? $id : null;
    }

    /**
     * @return list<array{point: string, run_id: string, origin: string|null}>
     */
    public function all(): array
    {
        $stack = Context::getHidden(self::KEY);

        if (! is_array($stack)) {
            return [];
        }

        $entries = [];

        foreach ($stack as $entry) {
            if (is_array($entry) && is_string($entry['point'] ?? null) && is_string($entry['run_id'] ?? null)) {
                $entries[] = ['point' => $entry['point'], 'run_id' => $entry['run_id'], 'origin' => is_string($entry['origin'] ?? null) ? $entry['origin'] : null];
            }
        }

        return $entries;
    }

    public function clear(): void
    {
        Context::forgetHidden(self::KEY);
        Context::forget(self::CURRENT);
    }
}
