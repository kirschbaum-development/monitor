<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kirschbaum\Monitor\Data\ControlledFailureMeta;
use Kirschbaum\Monitor\Facades\Monitor;
use Kirschbaum\Monitor\Support\ControlledContext;
use Throwable;

final class Controlled
{
    protected string $name;

    /** @var array<class-string<\Throwable>, \Closure> */
    protected array $exceptionHandlers = [];

    protected ?Closure $onUncaughtCallback = null;

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

    public function __construct(string $name, string|object|null $origin = null)
    {
        $this->name = $name;

        if ($origin !== null) {
            $this->withStructuredLogger(StructuredLogger::from($origin));
        }
    }

    public static function for(string $name, string|object|null $origin = null): self
    {
        return new self($name, $origin);
    }

    /**
     * Replace the entire context.
     *
     * @param  array<string, mixed>  $context
     */
    public function overrideContext(array $context): self
    {
        $this->context = $context;

        return $this;
    }

    /**
     * Merge additional context into existing context.
     *
     * @param  array<string, mixed>  $context
     */
    public function addContext(array $context): self
    {
        $this->context = array_merge($this->context, $context);

        return $this;
    }

    /**
     * Define exception-specific handlers
     *
     * @param  array<class-string<\Throwable>, \Closure>  $handlers
     */
    public function catching(array $handlers): self
    {
        $this->exceptionHandlers = $handlers;

        return $this;
    }

    public function onUncaughtException(Closure $callback): self
    {
        $this->onUncaughtCallback = $callback;

        return $this;
    }

    public function withCircuitBreaker(string $name, int $threshold = 5, int $decaySeconds = 300): self
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
    public function withDatabaseTransaction(int $retries = 0, array $only = [], array $exclude = []): self
    {
        $this->withTransaction = true;
        $this->transactionRetries = $retries;
        $this->retryOnlyExceptions = $only;
        $this->retryExcludeExceptions = $exclude;

        return $this;
    }

    public function overrideTraceId(string $traceId): self
    {
        $this->traceIdOverride = $traceId;

        return $this;
    }

    public function withStructuredLogger(StructuredLogger $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    public function run(Closure $callback): mixed
    {
        return $this->execute($callback);
    }

    protected function execute(Closure $callback): mixed
    {
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
                $circuitBreakerException = new \RuntimeException("Circuit breaker '{$this->breakerName}' is open");
                $this->handleUncaughtException($circuitBreakerException, $blockId, $trace->id(), $timer->elapsed());
                throw $circuitBreakerException;
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
                // Handle circuit breaker
                if ($this->breakerName) {
                    $this->recordCircuitBreakerFailure($this->breakerName);
                }

                // Try to handle the exception with catching handlers
                $handled = $this->handleCaughtException($e, $blockId, $trace->id(), $timer->elapsed());

                if ($handled) {
                    // Exception was caught and handled, don't re-throw
                    return $handled;
                }

                // Exception was not caught by any handler - this is an uncaught exception
                $this->handleUncaughtException($e, $blockId, $trace->id(), $timer->elapsed());

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

    protected function handleCaughtException(Throwable $e, string $blockId, string $traceId, float $durationMs): mixed
    {
        // Check if we have any exception handlers that match this exception
        foreach ($this->exceptionHandlers as $exceptionClass => $handler) {
            if ($e instanceof $exceptionClass) {
                $meta = new ControlledFailureMeta(
                    name: $this->name,
                    id: $blockId,
                    traceId: $traceId,
                    attempt: $this->attempt,
                    durationMs: $durationMs,
                    exception: $e,
                    context: $this->context,
                    breakerTripped: false,
                    uncaught: false,
                );

                $this->getLogger()->warning('CAUGHT', $meta->toArray());

                try {
                    $result = $handler($e, $meta->toArray());

                    // If handler returns a value, use it as recovery value
                    if ($result !== null) {
                        $this->getLogger()->info('RECOVERED', [
                            'controlled_block' => $this->name,
                            'controlled_block_id' => $blockId,
                            'exception_class' => get_class($e),
                            'recovery_value' => is_scalar($result) ? $result : gettype($result),
                        ]);

                        return $result;
                    }

                    return true; // Indicate exception was handled
                } catch (Throwable $handlerException) {
                    $this->getLogger()->error('EXCEPTION_HANDLER_ERROR', [
                        'controlled_block' => $this->name,
                        'controlled_block_id' => $blockId,
                        'exception_class' => $exceptionClass,
                        'handler_exception' => $handlerException->getMessage(),
                        'original_exception' => $e->getMessage(),
                    ]);

                    // If handler throws, treat as unhandled
                    return false;
                }
            }
        }

        return false; // No handler found
    }

    protected function handleUncaughtException(Throwable $e, string $blockId, string $traceId, float $durationMs): void
    {
        $meta = new ControlledFailureMeta(
            name: $this->name,
            id: $blockId,
            traceId: $traceId,
            attempt: $this->attempt,
            durationMs: $durationMs,
            exception: $e,
            context: $this->context,
            breakerTripped: false,
            uncaught: true,
        );

        $this->getLogger()->critical('UNCAUGHT', $meta->toArray());

        // Only call uncaught exception callback
        if ($this->onUncaughtCallback) {
            try {
                ($this->onUncaughtCallback)($e, $meta->toArray());
            } catch (Throwable $uncaughtCallbackException) {
                $this->getLogger()->error('UNCAUGHT_CALLBACK_ERROR', [
                    'controlled_block' => $this->name,
                    'controlled_block_id' => $blockId,
                    'callback_exception' => $uncaughtCallbackException->getMessage(),
                    'original_exception' => $e->getMessage(),
                ]);
            }
        }
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
