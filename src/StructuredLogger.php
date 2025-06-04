<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Kirschbaum\Monitor\Enums\OriginWrapper;
use Kirschbaum\Monitor\Facades\Monitor;
use Kirschbaum\Redactor\Facades\Redactor;

class StructuredLogger
{
    protected string $origin;

    protected string $rawOrigin;

    public static function from(string|object $origin): self
    {
        $instance = new self;
        $instance->rawOrigin = $instance->resolveOrigin($origin);

        $wrapper = OriginWrapper::fromConfig(Config::string('monitor.origin_path_wrapper', 'square'));
        $instance->origin = $wrapper->wrap($instance->rawOrigin);

        return $instance;
    }

    private function __construct(string|object|null $origin = null)
    {
        $this->rawOrigin = $this->resolveOrigin($origin);
        $this->origin = $this->rawOrigin;
    }

    public function getOrigin(): string
    {
        return $this->origin;
    }

    protected function resolveOrigin(string|object|null $origin): string
    {
        if (is_object($origin)) {
            $origin = get_class($origin);
        }

        if (! is_string($origin) || $origin === '') {
            return '';
        }

        /** @var array<string, string> $pathReplacers */
        $pathReplacers = Config::array('monitor.origin_path_replacers', []);

        // Use strtr() for efficient multi-point replacements applied in order
        if (! empty($pathReplacers)) {
            $origin = strtr($origin, $pathReplacers);
        }

        $separator = Config::string('monitor.origin_separator', ':');
        $origin = str_replace('\\', $separator, $origin);

        if ($prefix = Config::get('monitor.prefix')) {
            if (! is_string($prefix)) {
                throw new InvalidArgumentException('Monitor prefix must be a string');
            }

            $origin = $prefix.$separator.$origin;
        }

        return $origin;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function enrich(string $level, string $message, array $context = []): array
    {
        // Apply redaction to the context and message if enabled
        if (Config::boolean('monitor.redactor.enabled')) {
            $profile = Config::string('monitor.redactor.redactor_profile', 'default');
            $context = Redactor::redact($context, $profile);
            /** @var string $message */
            $message = Redactor::redact($message, $profile);
        }

        return [
            'level' => $level,
            'event' => "{$this->rawOrigin}:{$level}",
            'message' => "{$this->origin} {$message}",
            'trace_id' => Monitor::trace()->hasStarted() ? Monitor::trace()->id() : null,
            'context' => $context,
            'timestamp' => now()->toISOString(),
            'duration_ms' => Monitor::time()->elapsed(),
            'memory_mb' => round(memory_get_usage(true) / 1048576, 2),
        ];
    }

    protected function prefix(string $message): string
    {
        return "{$this->origin} {$message}";
    }

    /** @param  array<string, mixed>  $context */
    public function info(string $message, array $context = []): void
    {
        Log::info($this->prefix($message), $this->enrich('info', $message, $context));
    }

    /** @param  array<string, mixed>  $context */
    public function error(string $message, array $context = []): void
    {
        Log::error($this->prefix($message), $this->enrich('error', $message, $context));
    }

    /** @param  array<string, mixed>  $context */
    public function warning(string $message, array $context = []): void
    {
        Log::warning($this->prefix($message), $this->enrich('warning', $message, $context));
    }

    /** @param  array<string, mixed>  $context */
    public function debug(string $message, array $context = []): void
    {
        Log::debug($this->prefix($message), $this->enrich('debug', $message, $context));
    }

    /** @param  array<string, mixed>  $context */
    public function notice(string $message, array $context = []): void
    {
        Log::notice($this->prefix($message), $this->enrich('notice', $message, $context));
    }

    /** @param  array<string, mixed>  $context */
    public function critical(string $message, array $context = []): void
    {
        Log::critical($this->prefix($message), $this->enrich('critical', $message, $context));
    }

    /** @param  array<string, mixed>  $context */
    public function alert(string $message, array $context = []): void
    {
        Log::alert($this->prefix($message), $this->enrich('alert', $message, $context));
    }

    /** @param  array<string, mixed>  $context */
    public function emergency(string $message, array $context = []): void
    {
        Log::emergency($this->prefix($message), $this->enrich('emergency', $message, $context));
    }

    /** @param  array<string, mixed>  $context */
    public function log(string $level, string $message, array $context = []): void
    {
        Log::{$level}($this->prefix($message), $this->enrich($level, $message, $context));
    }
}
