<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Logging;

use Illuminate\Support\Facades\Log;
use Kirschbaum\Monitor\Support\Domain;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Stringable;

/**
 * A PSR-3 logger bound to an origin. Every record it writes carries the
 * origin class and its domain as context fields and a readable prefix on the
 * message, so lines from anywhere in a domain group together in a log backend.
 */
final class StructuredLogger implements LoggerInterface
{
    use LoggerTrait;

    private readonly string $origin;

    private readonly string $domain;

    /** @var array<string, mixed> */
    private array $context = [];

    public function __construct(string|object $origin, private ?string $channel = null)
    {
        $this->origin = is_object($origin) ? $origin::class : $origin;
        $this->domain = Domain::resolve($this->origin);
    }

    public function origin(): string
    {
        return $this->origin;
    }

    public function domain(): string
    {
        return $this->domain;
    }

    /**
     * Context added to every record from this logger.
     *
     * @param  array<string, mixed>  $context
     */
    public function with(array $context): self
    {
        $clone = clone $this;
        $clone->context = array_merge($this->context, $context);

        return $clone;
    }

    public function channel(string $channel): self
    {
        $clone = clone $this;
        $clone->channel = $channel;

        return $clone;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $writer = Log::channel($this->channel);

        $writer->log(is_string($level) || $level instanceof Stringable ? (string) $level : 'info', $this->prefix().' '.$message, array_merge(
            $this->context,
            $context,
            ['origin' => $this->origin, 'domain' => $this->domain],
        ));
    }

    public function prefix(): string
    {
        $short = substr($this->origin, (int) strrpos('\\'.$this->origin, '\\'));

        return sprintf('[%s:%s]', $this->domain, $short);
    }
}
