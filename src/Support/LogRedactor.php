<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Support;

use Illuminate\Support\Facades\Config;

class LogRedactor
{
    /**
     * Redact sensitive data from log context.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function redact(array $context): array
    {
        if (! Config::get('monitor.log_redactor.enabled', true)) {
            return $context;
        }

        /** @var array<string> $redactKeysConfig */
        $redactKeysConfig = Config::get('monitor.log_redactor.redact_keys', []);
        $redactKeys = array_map('strtolower', $redactKeysConfig);

        /** @var array<string, string> $patterns */
        $patterns = Config::get('monitor.log_redactor.patterns', []);

        /** @var string $replacement */
        $replacement = Config::get('monitor.log_redactor.replacement', '[REDACTED]');

        /** @var bool $markRedacted */
        $markRedacted = Config::get('monitor.log_redactor.mark_redacted', true);

        /** @var int|null $maxValueLength */
        $maxValueLength = Config::get('monitor.log_redactor.max_value_length');

        /** @var bool $redactLargeObjects */
        $redactLargeObjects = Config::get('monitor.log_redactor.redact_large_objects', true);

        /** @var int $maxObjectSize */
        $maxObjectSize = Config::get('monitor.log_redactor.max_object_size', 50);

        $wasRedacted = false;
        $redactedContext = $this->redactRecursively(
            $context,
            $redactKeys,
            $patterns,
            $replacement,
            $maxValueLength,
            $redactLargeObjects,
            $maxObjectSize,
            $wasRedacted
        );

        if (is_array($redactedContext) && $wasRedacted && $markRedacted) {
            $redactedContext['_redacted'] = true;
        }

        /** @var array<string, mixed> $result */
        $result = is_array($redactedContext) ? $redactedContext : $context;

        return $result;
    }

    /**
     * Recursively redact data from arrays and objects.
     *
     * @param  array<string>  $redactKeys
     * @param  array<string, string>  $patterns
     */
    protected function redactRecursively(
        mixed $data,
        array $redactKeys,
        array $patterns,
        string $replacement,
        ?int $maxValueLength,
        bool $redactLargeObjects,
        int $maxObjectSize,
        bool &$wasRedacted
    ): mixed {
        if (is_array($data)) {
            /** @var array<string, mixed> $arrayData */
            $arrayData = $data;

            return $this->redactArray(
                $arrayData,
                $redactKeys,
                $patterns,
                $replacement,
                $maxValueLength,
                $redactLargeObjects,
                $maxObjectSize,
                $wasRedacted
            );
        }

        if (is_object($data)) {
            return $this->redactObject(
                $data,
                $redactKeys,
                $patterns,
                $replacement,
                $maxValueLength,
                $redactLargeObjects,
                $maxObjectSize,
                $wasRedacted
            );
        }

        if (is_string($data)) {
            return $this->redactString($data, $patterns, $replacement, $maxValueLength, $wasRedacted);
        }

        return $data;
    }

    /**
     * Redact sensitive data from an array.
     *
     * @param  array<string, mixed>  $array
     * @param  array<string>  $redactKeys
     * @param  array<string, string>  $patterns
     * @return array<string, mixed>
     */
    protected function redactArray(
        array $array,
        array $redactKeys,
        array $patterns,
        string $replacement,
        ?int $maxValueLength,
        bool $redactLargeObjects,
        int $maxObjectSize,
        bool &$wasRedacted
    ): array {
        // Check if array is too large
        if ($redactLargeObjects && count($array) > $maxObjectSize) {
            $wasRedacted = true;

            return ['_large_object_redacted' => sprintf('%s (Array with %d items)', $replacement, count($array))];
        }

        $result = [];

        foreach ($array as $key => $value) {
            // Check if key should be redacted
            if (in_array(strtolower((string) $key), $redactKeys, true)) {
                $result[$key] = $replacement;
                $wasRedacted = true;

                continue;
            }

            // Recursively process the value
            $result[$key] = $this->redactRecursively(
                $value,
                $redactKeys,
                $patterns,
                $replacement,
                $maxValueLength,
                $redactLargeObjects,
                $maxObjectSize,
                $wasRedacted
            );
        }

        return $result;
    }

    /**
     * Redact sensitive data from an object.
     *
     * @param  array<string>  $redactKeys
     * @param  array<string, string>  $patterns
     */
    protected function redactObject(
        object $object,
        array $redactKeys,
        array $patterns,
        string $replacement,
        ?int $maxValueLength,
        bool $redactLargeObjects,
        int $maxObjectSize,
        bool &$wasRedacted
    ): mixed {
        // Convert object to array for processing
        $jsonString = json_encode($object);
        if ($jsonString === false) {
            return $object;
        }

        $array = json_decode($jsonString, true);

        if (! is_array($array)) {
            return $object;
        }

        // Check if object is too large
        if ($redactLargeObjects && count($array) > $maxObjectSize) {
            $wasRedacted = true;

            return ['_large_object_redacted' => sprintf('%s (Object %s with %d properties)', $replacement, get_class($object), count($array))];
        }

        /** @var array<string, mixed> $arrayData */
        $arrayData = $array;

        return $this->redactArray(
            $arrayData,
            $redactKeys,
            $patterns,
            $replacement,
            $maxValueLength,
            $redactLargeObjects,
            $maxObjectSize,
            $wasRedacted
        );
    }

    /**
     * Redact sensitive data from a string.
     *
     * @param  array<string, string>  $patterns
     */
    protected function redactString(
        string $string,
        array $patterns,
        string $replacement,
        ?int $maxValueLength,
        bool &$wasRedacted
    ): string {
        // Check if string is too long
        if ($maxValueLength !== null && strlen($string) > $maxValueLength) {
            $wasRedacted = true;

            return sprintf('%s (String with %d characters)', $replacement, strlen($string));
        }

        // Apply regex patterns
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $string)) {
                $wasRedacted = true;

                return $replacement;
            }
        }

        return $string;
    }
}
