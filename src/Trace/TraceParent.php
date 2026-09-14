<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Trace;

/**
 * The W3C traceparent header: version-traceid-spanid-flags.
 */
class TraceParent
{
    private const string PATTERN = '/^([0-9a-f]{2})-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/i';

    /**
     * The trace ID inside a valid traceparent, or null.
     */
    public static function parse(?string $header): ?string
    {
        if ($header === null || preg_match(self::PATTERN, trim($header), $m) !== 1) {
            return null;
        }

        $traceId = strtolower($m[2]);

        // All-zero IDs are invalid by the specification.
        if ($traceId === str_repeat('0', 32) || $m[3] === str_repeat('0', 16)) {
            return null;
        }

        return $traceId;
    }

    public static function format(string $traceId, ?string $spanId = null, bool $sampled = true): string
    {
        return sprintf('00-%s-%s-%s', $traceId, $spanId ?? self::spanId(), $sampled ? '01' : '00');
    }

    public static function spanId(): string
    {
        return bin2hex(random_bytes(8));
    }
}
