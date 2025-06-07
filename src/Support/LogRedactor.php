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

        // Get configuration
        /** @var array<string> $safeKeysConfig */
        $safeKeysConfig = Config::get('monitor.log_redactor.safe_keys', []);
        $safeKeys = array_map('strtolower', $safeKeysConfig);

        /** @var array<string> $blockedKeysConfig */
        $blockedKeysConfig = Config::get('monitor.log_redactor.blocked_keys', []);
        $blockedKeys = array_map('strtolower', $blockedKeysConfig);

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

        /** @var bool $enableShannonEntropy */
        $enableShannonEntropy = Config::get('monitor.log_redactor.shannon_entropy.enabled', true);

        /** @var float $entropyThreshold */
        $entropyThreshold = Config::get('monitor.log_redactor.shannon_entropy.threshold', 4.5);

        /** @var int $minLength */
        $minLength = Config::get('monitor.log_redactor.shannon_entropy.min_length', 20);

        $wasRedacted = false;
        $redactedContext = $this->redactRecursively(
            $context,
            $safeKeys,
            $blockedKeys,
            $patterns,
            $replacement,
            $maxValueLength,
            $redactLargeObjects,
            $maxObjectSize,
            $enableShannonEntropy,
            $entropyThreshold,
            $minLength,
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
     * @param  array<string>  $safeKeys
     * @param  array<string>  $blockedKeys
     * @param  array<string, string>  $patterns
     */
    protected function redactRecursively(
        mixed $data,
        array $safeKeys,
        array $blockedKeys,
        array $patterns,
        string $replacement,
        ?int $maxValueLength,
        bool $redactLargeObjects,
        int $maxObjectSize,
        bool $enableShannonEntropy,
        float $entropyThreshold,
        int $minLength,
        bool &$wasRedacted
    ): mixed {
        if (is_array($data)) {
            /** @var array<string, mixed> $arrayData */
            $arrayData = $data;

            return $this->redactArray(
                $arrayData,
                $safeKeys,
                $blockedKeys,
                $patterns,
                $replacement,
                $maxValueLength,
                $redactLargeObjects,
                $maxObjectSize,
                $enableShannonEntropy,
                $entropyThreshold,
                $minLength,
                $wasRedacted
            );
        }

        if (is_object($data)) {
            return $this->redactObject(
                $data,
                $safeKeys,
                $blockedKeys,
                $patterns,
                $replacement,
                $maxValueLength,
                $redactLargeObjects,
                $maxObjectSize,
                $enableShannonEntropy,
                $entropyThreshold,
                $minLength,
                $wasRedacted
            );
        }

        if (is_string($data)) {
            return $this->redactString(
                $data,
                $patterns,
                $replacement,
                $maxValueLength,
                $enableShannonEntropy,
                $entropyThreshold,
                $minLength,
                $wasRedacted
            );
        }

        return $data;
    }

    /**
     * Redact sensitive data from an array.
     *
     * @param  array<string, mixed>  $array
     * @param  array<string>  $safeKeys
     * @param  array<string>  $blockedKeys
     * @param  array<string, string>  $patterns
     * @return array<string, mixed>
     */
    protected function redactArray(
        array $array,
        array $safeKeys,
        array $blockedKeys,
        array $patterns,
        string $replacement,
        ?int $maxValueLength,
        bool $redactLargeObjects,
        int $maxObjectSize,
        bool $enableShannonEntropy,
        float $entropyThreshold,
        int $minLength,
        bool &$wasRedacted
    ): array {
        // Check if array is too large
        if ($redactLargeObjects && count($array) > $maxObjectSize) {
            $wasRedacted = true;

            return ['_large_object_redacted' => sprintf('%s (Array with %d items)', $replacement, count($array))];
        }

        $result = [];

        foreach ($array as $key => $value) {
            $keyLower = strtolower((string) $key);

            // Priority 1: Safe keys - always show unredacted
            if (in_array($keyLower, $safeKeys, true)) {
                $result[$key] = $value;

                continue;
            }

            // Priority 2: Blocked keys - always redact
            if (in_array($keyLower, $blockedKeys, true)) {
                $result[$key] = $replacement;
                $wasRedacted = true;

                continue;
            }

            // Priority 3 & 4: Recursively process the value (applies regex and shannon entropy)
            $result[$key] = $this->redactRecursively(
                $value,
                $safeKeys,
                $blockedKeys,
                $patterns,
                $replacement,
                $maxValueLength,
                $redactLargeObjects,
                $maxObjectSize,
                $enableShannonEntropy,
                $entropyThreshold,
                $minLength,
                $wasRedacted
            );
        }

        return $result;
    }

    /**
     * Redact sensitive data from an object.
     *
     * @param  array<string>  $safeKeys
     * @param  array<string>  $blockedKeys
     * @param  array<string, string>  $patterns
     */
    protected function redactObject(
        object $object,
        array $safeKeys,
        array $blockedKeys,
        array $patterns,
        string $replacement,
        ?int $maxValueLength,
        bool $redactLargeObjects,
        int $maxObjectSize,
        bool $enableShannonEntropy,
        float $entropyThreshold,
        int $minLength,
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
            $safeKeys,
            $blockedKeys,
            $patterns,
            $replacement,
            $maxValueLength,
            $redactLargeObjects,
            $maxObjectSize,
            $enableShannonEntropy,
            $entropyThreshold,
            $minLength,
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
        bool $enableShannonEntropy,
        float $entropyThreshold,
        int $minLength,
        bool &$wasRedacted
    ): string {
        // Check if string is too long
        if ($maxValueLength !== null && strlen($string) > $maxValueLength) {
            $wasRedacted = true;

            return sprintf('%s (String with %d characters)', $replacement, strlen($string));
        }

        // Priority 3: Apply regex patterns
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $string)) {
                $wasRedacted = true;

                return $replacement;
            }
        }

        // Priority 4: Shannon entropy analysis
        if ($enableShannonEntropy && $this->shouldRedactByEntropy($string, $entropyThreshold, $minLength)) {
            $wasRedacted = true;

            return $replacement;
        }

        return $string;
    }

    /**
     * Determine if a string should be redacted based on Shannon entropy.
     */
    protected function shouldRedactByEntropy(string $string, float $entropyThreshold, int $minLength): bool
    {
        // Only analyze strings that meet minimum length requirement
        if (strlen($string) < $minLength) {
            return false;
        }

        // Skip common words and patterns that might have high entropy but are not sensitive
        if ($this->isCommonPattern($string)) {
            return false;
        }

        $entropy = $this->calculateShannonEntropy($string);

        return $entropy >= $entropyThreshold;
    }

    /**
     * Calculate Shannon entropy of a string.
     */
    protected function calculateShannonEntropy(string $string): float
    {
        $length = strlen($string);
        if ($length <= 1) {
            return 0.0;
        }

        // Count character frequencies
        $frequencies = [];
        for ($i = 0; $i < $length; $i++) {
            $char = $string[$i];
            $frequencies[$char] = isset($frequencies[$char]) ? $frequencies[$char] + 1 : 1;
        }

        // Calculate entropy
        $entropy = 0.0;
        foreach ($frequencies as $frequency) {
            $probability = $frequency / $length;
            if ($probability > 0) {
                $entropy -= $probability * log($probability, 2);
            }
        }

        return $entropy;
    }

    /**
     * Check if a string matches common patterns that shouldn't be redacted despite high entropy.
     */
    protected function isCommonPattern(string $string): bool
    {
        // Skip URLs
        if (preg_match('/^https?:\/\//', $string)) {
            return true;
        }

        // Skip file paths
        if (preg_match('/^[\/\\\\].+[\/\\\\]/', $string)) {
            return true;
        }

        // Skip common date/time formats
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $string)) {
            return true;
        }

        // Skip UUIDs (they have high entropy but are often safe)
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $string)) {
            return true;
        }

        // Skip hexadecimal hashes that are too short to be sensitive
        if (preg_match('/^[0-9a-f]+$/i', $string) && strlen($string) < 32) {
            return true;
        }

        // Skip strings that are mostly whitespace
        if (preg_match('/^\s*$/', $string)) {
            return true;
        }

        // Skip user agents (common browser/application identifiers)
        if (preg_match('/^Mozilla\/\d\.\d|^[A-Za-z]+\/\d+\.\d+|AppleWebKit|Chrome|Safari|Firefox|Opera|Edge/', $string)) {
            return true;
        }

        // Skip IP addresses (IPv4 and simple IPv6)
        if (preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $string)) {
            return true;
        }

        // Skip MAC addresses
        if (preg_match('/^[0-9a-f]{2}:[0-9a-f]{2}:[0-9a-f]{2}:[0-9a-f]{2}:[0-9a-f]{2}:[0-9a-f]{2}$/i', $string)) {
            return true;
        }

        return false;
    }
}
