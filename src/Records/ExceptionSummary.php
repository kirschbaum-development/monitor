<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Records;

use Illuminate\Support\Facades\Config;
use Throwable;

/**
 * The part of an exception worth recording: class, message, location, code,
 * the previous exception one level down, and a trace only when configured.
 */
final class ExceptionSummary
{
    /**
     * @return array<string, mixed>
     */
    public static function from(Throwable $e): array
    {
        $summary = [
            'class' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'code' => $e->getCode(),
        ];

        $previous = $e->getPrevious();

        if ($previous instanceof Throwable) {
            $summary['previous'] = ['class' => $previous::class, 'message' => $previous->getMessage()];
        }

        if (self::includeTrace()) {
            $lines = explode("\n", $e->getTraceAsString());
            $max = max(1, Config::integer('monitor.records.exception_trace_lines', 15));

            $summary['trace'] = array_slice($lines, 0, $max);

            if (count($lines) > $max) {
                $summary['trace_truncated'] = count($lines) - $max;
            }
        }

        return $summary;
    }

    private static function includeTrace(): bool
    {
        return match (Config::string('monitor.records.exception_trace', 'never')) {
            'always' => true,
            'debug' => Config::boolean('app.debug', false),
            default => false,
        };
    }
}
