<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Support;

use Illuminate\Support\Facades\Config;
use Kirschbaum\Monitor\Facades\Monitor;

final class RedactorConfig
{
    public function __construct(
        /** @var array<string> */
        public array $safeKeys = [],
        /** @var array<string> */
        public array $blockedKeys = [],
        /** @var array<string> */
        public array $patterns = [],
        public string $replacement = '[REDACTED]',
        public ?int $maxValueLength = null,
        public bool $redactLargeObjects = true,
        public int $maxObjectSize = 50,
        public bool $enableShannonEntropy = true,
        public float $entropyThreshold = 4.5,
        public int $minLength = 20,
        /** @var array<string> */
        public array $entropyExclusionPatterns = [],
        public bool $markRedacted = true,
        public bool $trackRedactedKeys = false,
        public string $nonRedactableObjectBehavior = 'preserve'
    ) {}

    public static function fromConfig(): self
    {
        /** @var array<string> $safeKeys */
        $safeKeys = Config::array('monitor.log_redactor.safe_keys', []);
        /** @var array<string> $blockedKeys */
        $blockedKeys = Config::array('monitor.log_redactor.blocked_keys', []);
        /** @var array<string> $patterns */
        $patterns = Config::array('monitor.log_redactor.patterns', []);

        // Ensure we only have string values and apply transformations
        $safeKeysLower = array_map(function (string $key): string {
            return strtolower($key);
        }, $safeKeys);

        $blockedKeysLower = array_map(function (string $key): string {
            return strtolower($key);
        }, $blockedKeys);

        $validPatterns = array_filter($patterns, fn (string $pattern): bool => @preg_match($pattern, '') !== false);

        // Handle nullable max value length
        $maxValueLength = Config::get('monitor.log_redactor.max_value_length');
        $maxValueLengthTyped = is_int($maxValueLength) ? $maxValueLength : null;

        /** @var array<string> $entropyExclusionPatterns */
        $entropyExclusionPatterns = Config::array('monitor.log_redactor.shannon_entropy.exclusion_patterns', [
            '/^https?:\/\//',                                                           // URLs
            '/^[\/\\\\].+[\/\\\\]/',                                                   // File paths
            '/^\d{4}-\d{2}-\d{2}/',                                                    // Date formats
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',     // UUIDs
            '/^[0-9a-f]+$/i',                                                          // Hex strings (checked with length < 32)
            '/^\s*$/',                                                                 // Whitespace strings
            '/^Mozilla\/\d\.\d|^[A-Za-z]+\/\d+\.\d+|AppleWebKit|Chrome|Safari|Firefox|Opera|Edge/', // User agents
            '/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/',                                // IPv4 addresses
            '/^[0-9a-f]{2}:[0-9a-f]{2}:[0-9a-f]{2}:[0-9a-f]{2}:[0-9a-f]{2}:[0-9a-f]{2}$/i', // MAC addresses
        ]);

        $validExclusionPatterns = array_filter($entropyExclusionPatterns, fn (string $pattern): bool => @preg_match($pattern, '') !== false);

        return new self(
            safeKeys: $safeKeysLower,
            blockedKeys: $blockedKeysLower,
            patterns: array_values($validPatterns), // Re-index array
            replacement: Config::string('monitor.log_redactor.replacement', '[REDACTED]'),
            maxValueLength: $maxValueLengthTyped,
            redactLargeObjects: Config::boolean('monitor.log_redactor.redact_large_objects', true),
            maxObjectSize: Config::integer('monitor.log_redactor.max_object_size', 50),
            enableShannonEntropy: Config::boolean('monitor.log_redactor.shannon_entropy.enabled', true),
            entropyThreshold: Config::float('monitor.log_redactor.shannon_entropy.threshold', 4.5),
            minLength: Config::integer('monitor.log_redactor.shannon_entropy.min_length', 20),
            entropyExclusionPatterns: array_values($validExclusionPatterns), // Re-index array
            markRedacted: Config::boolean('monitor.log_redactor.mark_redacted', true),
            trackRedactedKeys: Config::boolean('monitor.log_redactor.track_redacted_keys', false),
            nonRedactableObjectBehavior: Config::string('monitor.log_redactor.non_redactable_object_behavior', 'preserve')
        );
    }
}

class LogRedactor
{
    /** @var array<string> */
    private array $redactedKeys = [];

    /** @var array<string, float> */
    private array $entropyCache = [];

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

        $config = RedactorConfig::fromConfig();
        $this->redactedKeys = [];
        $this->entropyCache = [];

        $wasRedacted = false;
        $redactedContext = $this->redactRecursively($context, $config, $wasRedacted);

        if (is_array($redactedContext) && $wasRedacted && $config->markRedacted) {
            $redactedContext['_redacted'] = true;

            if ($config->trackRedactedKeys && ! empty($this->redactedKeys)) {
                $redactedContext['_redacted_keys'] = array_unique($this->redactedKeys);
            }
        }

        /** @var array<string, mixed> $result */
        $result = is_array($redactedContext) ? $redactedContext : $context;

        return $result;
    }

    /**
     * Recursively redact data from arrays and objects.
     */
    protected function redactRecursively(
        mixed $data,
        RedactorConfig $config,
        bool &$wasRedacted
    ): mixed {
        if (is_array($data)) {
            /** @var array<string, mixed> $arrayData */
            $arrayData = $data;

            return $this->redactArray($arrayData, $config, $wasRedacted);
        }

        if (is_object($data)) {
            return $this->redactObject($data, $config, $wasRedacted);
        }

        if (is_string($data)) {
            return $this->redactString($data, $config, $wasRedacted);
        }

        return $data;
    }

    /**
     * Redact sensitive data from an array.
     *
     * @param  array<string, mixed>  $array
     * @return array<string, mixed>
     */
    protected function redactArray(
        array $array,
        RedactorConfig $config,
        bool &$wasRedacted
    ): array {
        // Check if array is too large
        if ($config->redactLargeObjects && count($array) > $config->maxObjectSize) {
            $wasRedacted = true;

            return ['_large_object_redacted' => sprintf('%s (Array with %d items)', $config->replacement, count($array))];
        }

        $result = [];

        foreach ($array as $key => $value) {
            $keyLower = strtolower((string) $key);

            // Priority 1: Safe keys - always show unredacted
            if (in_array($keyLower, $config->safeKeys, true)) {
                $result[$key] = $value;

                continue;
            }

            // Priority 2: Blocked keys - always redact
            if (in_array($keyLower, $config->blockedKeys, true)) {
                $result[$key] = $config->replacement;
                $wasRedacted = true;
                $this->redactedKeys[] = (string) $key;

                continue;
            }

            // Priority 3 & 4: Recursively process the value (applies regex and shannon entropy)
            $processedValue = $this->redactRecursively($value, $config, $wasRedacted);

            // Handle object removal case
            if ($processedValue === '__MONITOR_REMOVE_OBJECT__') {
                // Skip adding this key to the result (effectively removing it)
                continue;
            }

            $result[$key] = $processedValue;
        }

        return $result;
    }

    /**
     * Redact sensitive data from an object.
     */
    protected function redactObject(
        object $object,
        RedactorConfig $config,
        bool &$wasRedacted
    ): mixed {
        // Try to convert object to array using toArray() method if available
        if (method_exists($object, 'toArray')) {
            try {
                /** @var array<string, mixed> $array */
                $array = $object->toArray();

                return $this->redactArray($array, $config, $wasRedacted);
            } catch (\Throwable) {
                // Fall through to other methods
            }
        }

        // Try JSON encoding first to detect circular references and other issues
        try {
            $jsonString = json_encode($object, JSON_THROW_ON_ERROR);

            $array = json_decode($jsonString, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($array)) {
                // JSON decode didn't return an array
                Monitor::log('LogRedactor')->warning('Unable to redact object - JSON decode did not return array', [
                    'object_class' => get_class($object),
                    'reason' => 'json_decode_not_array',
                    'decoded_type' => gettype($array),
                    'behavior' => $config->nonRedactableObjectBehavior,
                ]);

                return $this->handleNonRedactableObject($object, $config, $wasRedacted);
            }

            // Check if object is too large
            if ($config->redactLargeObjects && count($array) > $config->maxObjectSize) {
                $wasRedacted = true;

                return ['_large_object_redacted' => sprintf('%s (Object %s with %d properties)', $config->replacement, get_class($object), count($array))];
            }

            /** @var array<string, mixed> $arrayData */
            $arrayData = $array;

            return $this->redactArray($arrayData, $config, $wasRedacted);
        } catch (\Throwable $e) {
            // If JSON encoding/decoding fails, it's likely due to circular references,
            // resources, or other non-serializable content. Return the original object
            // to avoid infinite recursion or other issues.
            Monitor::log('LogRedactor')->warning('Exception while trying to redact object', [
                'object_class' => get_class($object),
                'reason' => 'exception_during_processing',
                'exception_type' => get_class($e),
                'exception_message' => $e->getMessage(),
                'behavior' => $config->nonRedactableObjectBehavior,
            ]);

            return $this->handleNonRedactableObject($object, $config, $wasRedacted);
        }
    }

    /**
     * Handle objects that cannot be redacted based on configuration.
     */
    protected function handleNonRedactableObject(
        object $object,
        RedactorConfig $config,
        bool &$wasRedacted
    ): mixed {
        return match ($config->nonRedactableObjectBehavior) {
            'remove' => $this->removeObject($wasRedacted),
            'empty_array' => $this->replaceWithEmptyArray($wasRedacted),
            'redact' => $this->replaceWithRedactionText($object, $config, $wasRedacted),
            default => $object, // 'preserve' or any unknown value
        };
    }

    /**
     * Remove the object entirely (return a special marker that can be filtered out).
     */
    protected function removeObject(bool &$wasRedacted): string
    {
        $wasRedacted = true;

        return '__MONITOR_REMOVE_OBJECT__';
    }

    /** @return array<string, mixed> */
    protected function replaceWithEmptyArray(bool &$wasRedacted): array
    {
        $wasRedacted = true;

        return [];
    }

    /**
     * Replace with redaction text.
     */
    protected function replaceWithRedactionText(
        object $object,
        RedactorConfig $config,
        bool &$wasRedacted
    ): string {
        $wasRedacted = true;

        return sprintf('%s (Non-redactable object %s)', $config->replacement, get_class($object));
    }

    /**
     * Redact sensitive data from a string.
     */
    protected function redactString(
        string $string,
        RedactorConfig $config,
        bool &$wasRedacted
    ): string {
        // Check if string is too long
        if ($config->maxValueLength !== null && strlen($string) > $config->maxValueLength) {
            $wasRedacted = true;

            return sprintf('%s (String with %d characters)', $config->replacement, strlen($string));
        }

        // Priority 3: Apply regex patterns
        foreach ($config->patterns as $pattern) {
            if (preg_match($pattern, $string)) {
                $wasRedacted = true;

                return $config->replacement;
            }
        }

        // Priority 4: Shannon entropy analysis
        if ($config->enableShannonEntropy && $this->shouldRedactByEntropy($string, $config)) {
            $wasRedacted = true;

            return $config->replacement;
        }

        return $string;
    }

    /**
     * Determine if a string should be redacted based on Shannon entropy.
     */
    protected function shouldRedactByEntropy(string $string, RedactorConfig $config): bool
    {
        // Only analyze strings that meet minimum length requirement
        if (strlen($string) < $config->minLength) {
            return false;
        }

        // Skip common words and patterns that might have high entropy but are not sensitive
        if ($this->isCommonPattern($string, $config)) {
            return false;
        }

        $entropy = $this->calculateShannonEntropy($string);

        return $entropy >= $config->entropyThreshold;
    }

    /**
     * Calculate Shannon entropy of a string with caching.
     */
    protected function calculateShannonEntropy(string $string): float
    {
        // Check cache first
        if (isset($this->entropyCache[$string])) {
            return $this->entropyCache[$string];
        }

        $length = strlen($string);
        if ($length <= 1) {
            return $this->entropyCache[$string] = 0.0;
        }

        // Count character frequencies and calculate entropy in a single loop
        $frequencies = [];
        for ($i = 0; $i < $length; $i++) {
            $char = $string[$i];
            $frequencies[$char] = ($frequencies[$char] ?? 0) + 1;
        }

        // Calculate entropy
        $entropy = 0.0;
        foreach ($frequencies as $frequency) {
            $probability = $frequency / $length;
            if ($probability > 0) {
                $entropy -= $probability * log($probability, 2);
            }
        }

        // Cache the result
        $this->entropyCache[$string] = $entropy;

        return $entropy;
    }

    /**
     * Check if a string matches common patterns that shouldn't be redacted despite high entropy.
     */
    protected function isCommonPattern(string $string, RedactorConfig $config): bool
    {
        foreach ($config->entropyExclusionPatterns as $pattern) {
            if (preg_match($pattern, $string)) {
                // Special case: hex strings need additional length check
                if ($pattern === '/^[0-9a-f]+$/i' && strlen($string) >= 32) {
                    continue; // Long hex strings might be sensitive (like SHA256)
                }

                return true;
            }
        }

        return false;
    }
}
