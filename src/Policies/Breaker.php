<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Policies;

use Closure;
use Illuminate\Container\Container;
use Kirschbaum\Monitor\Breaker\BreakerConfig;
use Kirschbaum\Monitor\Breaker\CircuitBreaker;
use Kirschbaum\Monitor\Policies\Concerns\FiltersExceptions;
use Kirschbaum\Monitor\Risks\BreakerOpen;
use Kirschbaum\Monitor\Run;
use Throwable;

/**
 * Refuse to attempt the operation while a named circuit is open.
 *
 * The circuit opens after the configured number of failures inside the window,
 * stays open for the configured time, then lets one probe through: a success
 * closes it, a failure opens it again. Failures are counted per run, after
 * retries, so a retried-then-succeeded operation is a success.
 */
final class Breaker implements Policy
{
    use FiltersExceptions;

    private ?int $after = null;

    private ?int $within = null;

    private ?int $for = null;

    public function __construct(private readonly string $name) {}

    public static function named(string $name): self
    {
        return new self($name);
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * Open after this many failures inside the window (seconds).
     */
    public function after(int $failures, ?int $within = null): self
    {
        $this->after = max(1, $failures);

        if ($within !== null) {
            $this->within = max(1, $within);
        }

        return $this;
    }

    /**
     * Stay open for this many seconds before letting a probe through.
     */
    public function for(int $seconds): self
    {
        $this->for = max(1, $seconds);

        return $this;
    }

    public function around(Run $run, Closure $next): mixed
    {
        $circuit = Container::getInstance()->make(CircuitBreaker::class);
        $config = $this->config();

        $decision = $circuit->attempt($this->name, $config);

        if (! $decision->allowed) {
            $run->note('breaker.refused', ['breaker' => $this->name, 'retry_after_s' => $decision->retryAfterSeconds]);
            $refusal = new BreakerOpen($this->name, $decision->retryAfterSeconds);
            $run->refused($refusal);

            throw $refusal;
        }

        if ($decision->probe) {
            $run->note('breaker.probe', ['breaker' => $this->name]);
        }

        try {
            $value = $next();
        } catch (Throwable $e) {
            if ($this->applies($e)) {
                $circuit->recordFailure($this->name, $config);
                $run->note('breaker.failure', ['breaker' => $this->name, 'state' => $circuit->state($this->name)->state->value]);
            }

            throw $e;
        }

        $circuit->recordSuccess($this->name);

        return $value;
    }

    public function order(): int
    {
        return Policy::ORDER_BREAKER;
    }

    public function describe(): array
    {
        $config = $this->config();

        return [
            'type' => 'breaker',
            'name' => $this->name,
            'after' => $config->after,
            'within' => $config->within,
            'for' => $config->for,
            'on' => $this->only,
            'except' => $this->except,
        ];
    }

    public function config(): BreakerConfig
    {
        return BreakerConfig::defaults()->with($this->after, $this->within, $this->for);
    }
}
