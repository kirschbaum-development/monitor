<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Logging;

use Monolog\Formatter\NormalizerFormatter;
use Monolog\LogRecord;

/**
 * One JSON object per line with the record's fields at the top level, so a
 * log backend indexes "point", "domain" and "status" as fields rather than
 * as text inside a context blob. Context keys win over extra keys, and both
 * win over nothing; the four envelope keys are always present.
 */
class RecordFormatter extends NormalizerFormatter
{
    public function __construct()
    {
        parent::__construct('Y-m-d\TH:i:s.vP');
    }

    public function format(LogRecord $record): string
    {
        /** @var array<string, mixed> $normalized */
        $normalized = parent::format($record);

        $line = [
            'ts' => $normalized['datetime'] ?? null,
            'level' => strtolower($record->level->getName()),
            'channel' => $normalized['channel'] ?? $record->channel,
            'message' => $normalized['message'] ?? $record->message,
        ];

        $extra = is_array($normalized['extra'] ?? null) ? $normalized['extra'] : [];
        $context = is_array($normalized['context'] ?? null) ? $normalized['context'] : [];

        foreach ($extra as $key => $value) {
            $line[(string) $key] ??= $value;
        }

        foreach ($context as $key => $value) {
            $line[(string) $key] = $value;
        }

        $json = json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return ($json === false ? '{"message":"[monitor] record could not be encoded"}' : $json)."\n";
    }
}
