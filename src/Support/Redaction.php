<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Support;

use Illuminate\Support\Facades\Config;
use Kirschbaum\Redactor\Facades\Redactor;
use Throwable;

/**
 * Redacts the parts of a record that carry application data: the context and
 * any exception messages. Identifiers, names and numbers are left alone so a
 * record stays joinable.
 *
 * @internal
 */
class Redaction
{
    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    public static function record(array $record): array
    {
        if (isset($record['context']) && is_array($record['context'])) {
            $record['context'] = self::context($record['context']);
        }

        foreach (['exception', 'escalation_exception'] as $key) {
            if (isset($record[$key]) && is_array($record[$key])) {
                $record[$key] = self::exception($record[$key]);
            }
        }

        return $record;
    }

    /**
     * @param  array<mixed>  $context
     * @return array<mixed>
     */
    public static function context(array $context): array
    {
        $profile = self::profile();

        if ($profile === null) {
            return $context;
        }

        try {
            $redacted = Redactor::profile($profile)->withoutMarkers()->redactSafely($context);
        } catch (Throwable) {
            // A record must never fail a control point; without a redactor the context is dropped rather than leaked.
            return ['_redaction' => 'unavailable'];
        }

        return is_array($redacted) ? $redacted : [];
    }

    /**
     * @param  array<mixed>  $exception
     * @return array<mixed>
     */
    public static function exception(array $exception): array
    {
        $profile = self::profile();

        if ($profile === null) {
            return $exception;
        }

        foreach (['message', 'previous'] as $key) {
            if (! isset($exception[$key])) {
                continue;
            }

            try {
                $exception[$key] = Redactor::profile($profile)->withoutMarkers()->redactSafely($exception[$key]);
            } catch (Throwable) {
                $exception[$key] = '[redaction unavailable]';
            }
        }

        return $exception;
    }

    public static function profile(): ?string
    {
        $profile = Config::get('monitor.records.redaction');

        return is_string($profile) && $profile !== '' ? $profile : null;
    }
}
