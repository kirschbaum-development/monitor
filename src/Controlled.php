<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor;

use Closure;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kirschbaum\Monitor\Data\ControlledFailureMeta;
use Kirschbaum\Monitor\Facades\Monitor;
use Kirschbaum\Monitor\Support\ControlledContext;
use Throwable;

final class Controlled
{
    protected ?string $name = null;

    protected ?Closure $onFail = null;

    protected ?Closure $onEscalate = null;

    /** @var array<string, mixed> */
    protected array $context = [];

    protected ?string $breakerName = null;

    protected int $breakerThreshold = 5;

    protected int $breakerDecaySeconds = 300;

    protected bool $withTransaction = false;

    protected int $transactionRetries = 0;

    /** @var array<class-string<\Throwable>> */
    protected array $retryOnlyExceptions = [];

    /** @var array<class-string<\Throwable>> */
    protected array $retryExcludeExceptions = [];

    protected int $attempt = 1;

    protected ?string $traceIdOverride = null;

    protected ?StructuredLogger $logger = null;

    public static function for(string $name): self
    {
        $instance = new self;
        $instance->name = $name;

        return $instance;
    }

    /**
     * Replace the entire context.
     *
     * @param  array<string, mixed>  $context
     */
    public function context(array $context): self
    {
        $this->context = $context;

        return $this;
    }

    /**
     * Merge additional context into existing context.
     *
     * @param  array<string, mixed>  $context
     */
    public function with(array $context): self
    {
        $this->context = array_merge($this->context, $context);

        return $this;
    }

    public function failing(Closure $callback): self
    {
        $this->onFail = $callback;

        return $this;
    }

    public function escalated(Closure $callback): self
    {
        $this->onEscalate = $callback;

        return $this;
    }

    public function breaker(string $name, int $threshold = 5, int $decaySeconds = 300): self
    {
        $this->breakerName = $name;
        $this->breakerThreshold = $threshold;
        $this->breakerDecaySeconds = $decaySeconds;

        return $this;
    }

    /**
     * @param  array<class-string<\Throwable>>  $only
     * @param  array<class-string<\Throwable>>  $exclude
     */
    public function transactioned(int $retries = 0, array $only = [], array $exclude = []): self
    {
        $this->withTransaction = true;
        $this->transactionRetries = $retries;
        $this->retryOnlyExceptions = $only;
        $this->retryExcludeExceptions = $exclude;

        return $this;
    }

    public function traceId(string $traceId): self
    {
        $this->traceIdOverride = $traceId;

        return $this;
    }

    public function from(string|object $origin): self
    {
        $this->logger = StructuredLogger::from($origin);

        return $this;
    }

    public function run(Closure $callback): mixed
    {
        return $this->execute($callback);
    }

    protected function execute(Closure $callback): mixed
    {
        if (! $this->name) {
            throw new \InvalidArgumentException('Controlled block name is required');
        }

        if ($this->traceIdOverride) {
            Monitor::trace()->override($this->traceIdOverride);
        }

        $trace = Monitor::trace()->pickup();
        $timer = new LogTimer;
        $blockId = Str::uuid()->toString();

        // Check for nested Controlled blocks
        $contextTracker = app(ControlledContext::class);
        $contextTracker->enter($this->name, $blockId);

        try {
            // Check circuit breaker
            if ($this->breakerName && $this->isCircuitBreakerOpen($this->breakerName)) {
                $this->logFailure($blockId, $trace->id(), $timer->elapsed(), null, true);
                throw new \RuntimeException("Circuit breaker '{$this->breakerName}' is open");
            }

            /** @var array<string, mixed> $startContext */
            $startContext = [
                'controlled_block' => $this->name,
                'controlled_block_id' => $blockId,
                'trace_id' => $trace->id(),
                'attempt' => $this->attempt,
                'block_context' => $this->context,
            ];

            $this->getLogger()->info('STARTED', $startContext);

            try {
                $result = $this->withTransaction
                    ? $this->executeWithTransaction($callback)
                    : $callback();

                /** @var array<string, mixed> $endContext */
                $endContext = [
                    'controlled_block' => $this->name,
                    'controlled_block_id' => $blockId,
                    'trace_id' => $trace->id(),
                    'status' => 'ok',
                    'duration_ms' => $timer->elapsed(),
                    'attempt' => $this->attempt,
                    'block_context' => $this->context,
                ];

                $this->getLogger()->info('ENDED', $endContext);

                // Reset circuit breaker on success
                if ($this->breakerName) {
                    $this->resetCircuitBreaker($this->breakerName);
                }

                return $result;
            } catch (Throwable $e) {
                $this->logFailure($blockId, $trace->id(), $timer->elapsed(), $e);

                // Handle circuit breaker
                if ($this->breakerName) {
                    $this->recordCircuitBreakerFailure($this->breakerName);
                }

                throw $e;
            }
        } finally {
            $contextTracker->exit();
        }
    }

    protected function executeWithTransaction(Closure $callback): mixed
    {
        $attempts = 0;
        $maxAttempts = $this->transactionRetries + 1;
        $lastException = null;

        while ($attempts < $maxAttempts) {
            $this->attempt = $attempts + 1;

            try {
                return DB::transaction(function () use ($callback) {
                    return $callback();
                });
            } catch (Throwable $e) {
                $lastException = $e;
                $attempts++;

                if (! $this->shouldRetryException($e)) {
                    throw $e;
                }

                if ($attempts < $maxAttempts) {
                    // Log retry attempt
                    $this->getLogger()->warning('RETRY', [
                        'controlled_block' => $this->name,
                        'attempt' => $this->attempt,
                        'max_attempts' => $maxAttempts,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        throw $lastException ?? new \RuntimeException('Transaction failed with unknown error.');
    }

    protected function logFailure(string $blockId, string $traceId, float $durationMs, ?Throwable $e, bool $breakerTripped = false): void
    {
        $exception = $e ? $this->flattenException($e) : null;

        $meta = new ControlledFailureMeta(
            name: $this->name ?? 'unknown',
            id: $blockId,
            traceId: $traceId,
            attempt: $this->attempt,
            durationMs: $durationMs,
            exception: $e,
            context: $this->context,
            breakerTripped: $breakerTripped,
            escalated: false,
        );

        $this->getLogger()->critical('FAILED', $meta->toArray());

        if ($this->onFail) {
            try {
                ($this->onFail)($e, $meta->toArray());
            } catch (Throwable $callbackException) {
                $this->getLogger()->error('FAILURE_CALLBACK_ERROR', [
                    'controlled_block' => $this->name,
                    'controlled_block_id' => $blockId,
                    'original_exception' => $e?->getMessage(),
                    'callback_exception' => $callbackException->getMessage(),
                ]);
            }
        }

        if ($this->onEscalate) {
            try {
                ($this->onEscalate)($meta->toArray());
            } catch (Throwable $escalationException) {
                $this->getLogger()->error('ESCALATION_CALLBACK_ERROR', [
                    'controlled_block' => $this->name,
                    'controlled_block_id' => $blockId,
                    'escalation_exception' => $escalationException->getMessage(),
                ]);
            }
        }
    }

    /** @return array<string, mixed>|null */
    private function flattenException(Throwable $e): ?array
    {
        if (! Config::boolean('monitor.exception_trace.enabled', true)) {
            return null;
        }

        $isDebug = Config::boolean('app.debug') || Config::boolean('monitor.exception_trace.force_full_trace', false);
        $fullTrace = explode("\n", $e->getTraceAsString());

        return [
            'class' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $isDebug && Config::boolean('monitor.exception_trace.full_on_debug', true)
                ? $fullTrace
                : array_slice($fullTrace, 0, Config::integer('monitor.exception_trace.max_lines', 15)),
        ];
    }

    protected function isCircuitBreakerOpen(string $breakerName): bool
    {
        return app(CircuitBreaker::class)->isOpen(
            $breakerName,
            $this->breakerThreshold,
            $this->breakerDecaySeconds
        );
    }

    protected function recordCircuitBreakerFailure(string $breakerName): void
    {
        app(CircuitBreaker::class)->recordFailure(
            $breakerName,
            $this->breakerDecaySeconds
        );
    }

    protected function resetCircuitBreaker(string $breakerName): void
    {
        app(CircuitBreaker::class)->recordSuccess($breakerName);
    }

    protected function shouldRetryException(Throwable $e): bool
    {
        // If only specific exceptions are allowed, check if this one is included
        if (! empty($this->retryOnlyExceptions)) {
            foreach ($this->retryOnlyExceptions as $allowedClass) {
                if ($e instanceof $allowedClass) {
                    break;
                }
            }
            // If we didn't find a match, don't retry
            if (! ($e instanceof $allowedClass)) {
                return false;
            }
        }

        // If this exception is explicitly excluded, don't retry
        foreach ($this->retryExcludeExceptions as $excludedClass) {
            if ($e instanceof $excludedClass) {
                return false;
            }
        }

        return true;
    }

    protected function getLogger(): StructuredLogger
    {
        return $this->logger ?? Monitor::log("ControlledBlock:{$this->name}");
    }
}
