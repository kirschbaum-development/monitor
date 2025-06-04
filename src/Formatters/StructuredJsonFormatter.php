<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Formatters;

use Monolog\Formatter\JsonFormatter;
use Monolog\LogRecord;

class StructuredJsonFormatter extends JsonFormatter
{
    public function format(LogRecord $record): string
    {
        $structured = [
            'timestamp' => $record->datetime->format('c'),
            'level' => $record->level->getName(),
            'message' => $record->message,
            'trace_id' => $record->context['trace_id'] ?? null,
            'context' => $record->context,
            'channel' => $record->channel,
            'event' => $record->extra['event'] ?? null,
            'duration_ms' => $record->context['duration_ms'] ?? null,
            'memory_mb' => $record->context['memory_mb'] ?? null,
        ];

        return json_encode($structured)."\n"; // newline-delimited for NDJSON
    }
}
