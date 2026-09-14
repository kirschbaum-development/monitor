<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Support;

use Illuminate\Support\Facades\Config;
use Kirschbaum\Monitor\Contracts\Policy;
use Kirschbaum\Monitor\Exceptions\InvalidProfile;
use Kirschbaum\Monitor\Limits\Attempts;
use Kirschbaum\Monitor\Limits\Within;
use Kirschbaum\Monitor\Policies\Breaker;
use Kirschbaum\Monitor\Policies\Retry;
use Kirschbaum\Monitor\Policies\Transaction;

/**
 * Turns a configured profile into the policies and limits it bundles.
 *
 * @internal
 */
class Profiles
{
    /**
     * @return list<string>
     */
    public static function names(): array
    {
        /** @var array<string, mixed> $profiles */
        $profiles = Config::array('monitor.profiles', []);

        return array_map(strval(...), array_keys($profiles));
    }

    public static function exists(string $name): bool
    {
        return in_array($name, self::names(), true);
    }

    /**
     * @return array<string, mixed>
     */
    public static function definition(string $name): array
    {
        $definition = Config::get("monitor.profiles.{$name}");

        if (! is_array($definition)) {
            throw new InvalidProfile(sprintf('Profile [%s] is not defined in config/monitor.php.', $name));
        }

        /** @var array<string, mixed> $definition */
        return $definition;
    }

    /**
     * Policies keyed by type: retry, transaction, breaker.
     *
     * @return array<string, Policy>
     */
    public static function policies(string $name, string $point): array
    {
        $definition = self::definition($name);
        $policies = [];

        if (is_array($definition['retry'] ?? null)) {
            $retry = $definition['retry'];
            $policy = Retry::times(self::int($retry, 'times', 1))
                ->backoff(self::int($retry, 'backoff_ms', 0), self::float($retry, 'multiplier', 2.0), self::bool($retry, 'jitter', true));
            $policies['retry'] = $policy->on(self::classes($retry, 'on'))->except(self::classes($retry, 'except'));
        }

        if (is_array($definition['transaction'] ?? null)) {
            $tx = $definition['transaction'];
            $policy = Transaction::retries(self::int($tx, 'retries', 0))->backoff(self::int($tx, 'backoff_ms', 0));
            $connection = $tx['connection'] ?? null;
            $policy->connection(is_string($connection) ? $connection : null);

            if (isset($tx['on'])) {
                $policy->on(self::classes($tx, 'on'));
            }

            $policies['transaction'] = $policy->except(self::classes($tx, 'except'));
        }

        if (is_array($definition['breaker'] ?? null)) {
            $breaker = $definition['breaker'];
            $breakerName = is_string($breaker['name'] ?? null) ? $breaker['name'] : $point;
            $policy = Breaker::named($breakerName);

            if (isset($breaker['after'])) {
                $policy->after(self::int($breaker, 'after', 5), isset($breaker['within']) ? self::int($breaker, 'within', 60) : null);
            }

            if (isset($breaker['for'])) {
                $policy->for(self::int($breaker, 'for', 120));
            }

            $policies['breaker'] = $policy->on(self::classes($breaker, 'on'))->except(self::classes($breaker, 'except'));
        }

        return $policies;
    }

    public static function within(string $name): ?Within
    {
        $definition = self::definition($name);
        $seconds = $definition['within'] ?? null;

        return is_int($seconds) || is_float($seconds) ? new Within((float) $seconds) : null;
    }

    public static function attempts(string $name): ?Attempts
    {
        $definition = self::definition($name);
        $max = $definition['attempts'] ?? null;

        return is_int($max) ? new Attempts($max) : null;
    }

    /**
     * @param  array<mixed>  $source
     */
    private static function int(array $source, string $key, int $default): int
    {
        $value = $source[$key] ?? $default;

        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : $default);
    }

    /**
     * @param  array<mixed>  $source
     */
    private static function float(array $source, string $key, float $default): float
    {
        $value = $source[$key] ?? $default;

        return is_numeric($value) ? (float) $value : $default;
    }

    /**
     * @param  array<mixed>  $source
     */
    private static function bool(array $source, string $key, bool $default): bool
    {
        $value = $source[$key] ?? $default;

        return is_bool($value) ? $value : $default;
    }

    /**
     * @param  array<mixed>  $source
     * @return list<class-string<\Throwable>>
     */
    private static function classes(array $source, string $key): array
    {
        $value = $source[$key] ?? [];

        if (! is_array($value)) {
            return [];
        }

        /** @var list<class-string<\Throwable>> $classes */
        $classes = array_values(array_filter($value, fn (mixed $c): bool => is_string($c) && is_a($c, \Throwable::class, true)));

        return $classes;
    }
}
