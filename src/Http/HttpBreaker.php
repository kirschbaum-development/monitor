<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Http;

use Closure;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Kirschbaum\Monitor\Breaker\BreakerConfig;
use Kirschbaum\Monitor\Breaker\CircuitBreaker;
use Kirschbaum\Monitor\Risks\BreakerOpen;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Registers Http::breaker('stripe') and $pendingRequest->breaker('stripe').
 *
 * The request is refused with BreakerOpen while the circuit is open, a
 * connection failure or a 5xx response counts as a failure, and anything else
 * counts as a success. It is the same circuit a control point's breaker()
 * uses, so an outbound call and the point that wraps another call to the same
 * dependency stop together.
 */
class HttpBreaker
{
    /**
     * @param  Closure(): CircuitBreaker  $breaker  resolves the breaker lazily, so nothing is built at boot
     */
    public static function register(Closure $breaker): void
    {
        $middleware = static fn (string $name, ?int $after, ?int $within, ?int $for): Closure => static::middleware($breaker, $name, BreakerConfig::defaults()->with($after, $within, $for));

        PendingRequest::macro('breaker', function (string $name, ?int $after = null, ?int $within = null, ?int $for = null) use ($middleware): PendingRequest {
            /** @var PendingRequest $this */
            return $this->withMiddleware($middleware($name, $after, $within, $for));
        });

        Factory::macro('breaker', function (string $name, ?int $after = null, ?int $within = null, ?int $for = null) use ($middleware): PendingRequest {
            /** @var Factory $this */
            return $this->withMiddleware($middleware($name, $after, $within, $for));
        });
    }

    /**
     * A Guzzle middleware that consults the circuit before sending and records the result.
     *
     * @param  Closure(): CircuitBreaker  $breaker
     */
    public static function middleware(Closure $breaker, string $name, BreakerConfig $config): Closure
    {
        return static fn (callable $handler): Closure => static function (RequestInterface $request, array $options) use ($handler, $breaker, $name, $config): PromiseInterface {
            $decision = $breaker()->permit($name, $config);

            if (! $decision->allowed) {
                throw new BreakerOpen($name, $decision->retryAfterSeconds);
            }

            try {
                /** @var PromiseInterface $promise */
                $promise = $handler($request, $options);
            } catch (Throwable $e) {
                // A handler that fails before producing a promise, as a faked
                // connection failure does, is a failure of the dependency too.
                $breaker()->recordFailure($name, $config);

                throw $e;
            }

            return $promise->then(
                static function (ResponseInterface $response) use ($breaker, $name, $config): ResponseInterface {
                    if ($response->getStatusCode() >= 500) {
                        $breaker()->recordFailure($name, $config);
                    } else {
                        $breaker()->recordSuccess($name);
                    }

                    return $response;
                },
                static function (mixed $reason) use ($breaker, $name, $config): PromiseInterface {
                    $breaker()->recordFailure($name, $config);

                    return Create::rejectionFor($reason instanceof Throwable ? $reason : (string) json_encode($reason));
                },
            );
        };
    }
}
