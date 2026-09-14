<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Trace;

use Illuminate\Support\Facades\Context;
use Kirschbaum\Monitor\Exceptions\InvalidTraceId;

/**
 * The trace ID for the current request, job or command.
 *
 * Stored in Laravel's Context under "trace_id", so it reaches queued jobs and
 * every log line the application writes. Trace IDs are 32 lowercase hex
 * characters, the W3C form; a UUID is accepted and normalised to that.
 */
class Trace
{
    public const string KEY = 'trace_id';

    public function hasStarted(): bool
    {
        return $this->current() !== null;
    }

    public function current(): ?string
    {
        $id = Context::get(self::KEY);

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * The current trace ID, starting one if none exists.
     */
    public function id(): string
    {
        return $this->pickup();
    }

    /**
     * Start a new trace, replacing any that exists.
     */
    public function start(): string
    {
        return $this->override(self::generate());
    }

    /**
     * Continue the current trace, or adopt the given ID, or start a new one.
     */
    public function pickup(?string $id = null): string
    {
        $current = $this->current();

        if ($current !== null) {
            return $current;
        }

        if ($id !== null) {
            return $this->override($id);
        }

        return $this->start();
    }

    /**
     * Adopt the given ID, replacing any that exists.
     *
     * @throws InvalidTraceId when the ID is neither 32 hex characters nor a UUID
     */
    public function override(string $id): string
    {
        $normalised = self::normalise($id);

        if ($normalised === null) {
            throw new InvalidTraceId(sprintf('[%s] is not a valid trace ID.', $id));
        }

        Context::add(self::KEY, $normalised);

        return $normalised;
    }

    public function clear(): void
    {
        Context::forget(self::KEY);
    }

    public static function generate(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * 32 hex characters, or a UUID with its dashes removed; anything else is null.
     */
    public static function normalise(string $id): ?string
    {
        $candidate = strtolower(str_replace('-', '', trim($id)));

        if (preg_match('/^[0-9a-f]{32}$/', $candidate) !== 1 || $candidate === str_repeat('0', 32)) {
            return null;
        }

        return $candidate;
    }
}
