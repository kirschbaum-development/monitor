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
final class ControlStack
{
    public const string KEY = 'monitor.stack';

    public const string CURRENT = 'control_point';

    public function push(string $point, string $runId): void
    {
        $stack = $this->all();
        $stack[] = ['point' => $point, 'run_id' => $runId];

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
     * @return array{point: string, run_id: string}|null
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
     * @return list<array{point: string, run_id: string}>
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
                $entries[] = ['point' => $entry['point'], 'run_id' => $entry['run_id']];
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
