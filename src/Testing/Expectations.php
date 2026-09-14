<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Testing;

use Closure;
use Illuminate\Container\Container;
use InvalidArgumentException;
use Kirschbaum\Monitor\Inventory\Discovery;
use Kirschbaum\Monitor\Inventory\Finding;
use Kirschbaum\Monitor\Inventory\PointDescription;
use Kirschbaum\Monitor\Inventory\Rules\CriticalNamespaceUncontrolled;
use PHPUnit\Framework\Assert;

/**
 * Pest expectations. Call once from tests/Pest.php:
 *
 *     Kirschbaum\Monitor\Testing\Expectations::register();
 *
 * Then:
 *
 *     expect('App\Services\Payments')->toBeControlled();
 *     expect('App\ControlPoints')->toHaveCompleteControlPoints();
 *
 * The assertions are also plain static methods, for PHPUnit.
 */
class Expectations
{
    /**
     * The subject an expectation was built on; Pest rebinds the closures below
     * to its own Expectation, which has the same property.
     */
    public mixed $value = null;

    public static function register(): void
    {
        $mixin = new self;

        expect()->extend('toBeControlled', $mixin->toBeControlled());
        expect()->extend('toHaveCompleteControlPoints', $mixin->toHaveCompleteControlPoints());
    }

    /**
     * Every class in the namespace is a control point class or calls one inline.
     */
    public static function assertControlled(string $namespace): void
    {
        $inventory = self::discovery()->build(rules: []);
        $findings = self::withNamespaces([$namespace], fn (): array => (new CriticalNamespaceUncontrolled)->check($inventory));

        Assert::assertEmpty($findings, self::describe($findings, "Uncontrolled operations in {$namespace}:"));
    }

    /**
     * Every class-form point in the namespace passes the configured rules.
     */
    public static function assertComplete(string $namespace): void
    {
        $inventory = self::discovery()->build();
        $prefix = rtrim($namespace, '\\').'\\';

        $findings = array_values(array_filter($inventory->findings(), function (Finding $finding) use ($inventory, $prefix): bool {
            if (! $finding->isError()) {
                return false;
            }

            $point = $finding->point !== null ? $inventory->find($finding->point) : null;

            return $point instanceof PointDescription && str_starts_with($point->origin, $prefix);
        }));

        Assert::assertEmpty($findings, self::describe($findings, "Incomplete control points in {$namespace}:"));
    }

    /**
     * @internal
     */
    public static function namespaceOf(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException('Expected a namespace string.');
        }

        return $value;
    }

    private function toBeControlled(): Closure
    {
        return function (): object {
            Expectations::assertControlled(Expectations::namespaceOf($this->value));

            return $this;
        };
    }

    private function toHaveCompleteControlPoints(): Closure
    {
        return function (): object {
            Expectations::assertComplete(Expectations::namespaceOf($this->value));

            return $this;
        };
    }

    /**
     * @param  list<Finding>  $findings
     */
    private static function describe(array $findings, string $heading): string
    {
        return $heading."\n".implode("\n", array_map(fn (Finding $f): string => ' - '.$f->message, $findings));
    }

    /**
     * @param  list<string>  $namespaces
     * @param  callable(): list<Finding>  $callback
     * @return list<Finding>
     */
    private static function withNamespaces(array $namespaces, callable $callback): array
    {
        $config = Container::getInstance()->make('config');
        $previous = $config->get('monitor.critical_namespaces');
        $config->set('monitor.critical_namespaces', $namespaces);

        try {
            return $callback();
        } finally {
            $config->set('monitor.critical_namespaces', $previous);
        }
    }

    private static function discovery(): Discovery
    {
        return Container::getInstance()->make(Discovery::class);
    }
}
