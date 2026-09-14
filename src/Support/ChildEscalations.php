<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Support;

use Throwable;
use WeakMap;

/**
 * Exceptions that escalated out of a nested control point.
 *
 * A parent's retry policy consults this so it never re-runs a child that has
 * already exhausted its own attempts: retries do not compose across the stack.
 * The parent's corrections still see the exception like any other.
 */
final class ChildEscalations
{
    /** @var WeakMap<Throwable, string>|null */
    private static ?WeakMap $escalations = null;

    public static function record(Throwable $exception, string $point): void
    {
        self::map()[$exception] = $point;
    }

    public static function contains(Throwable $exception): bool
    {
        return isset(self::map()[$exception]);
    }

    public static function pointFor(Throwable $exception): ?string
    {
        return self::map()[$exception] ?? null;
    }

    /**
     * @return WeakMap<Throwable, string>
     */
    private static function map(): WeakMap
    {
        return self::$escalations ??= new WeakMap;
    }
}
