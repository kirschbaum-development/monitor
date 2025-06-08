<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Support;

use Illuminate\Support\Facades\Config;

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
        $entropyExclusionPatterns = Config::array('monitor.log_redactor.shannon_entropy.exclusion_patterns');

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
