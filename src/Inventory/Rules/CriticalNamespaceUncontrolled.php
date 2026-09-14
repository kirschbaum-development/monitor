<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Inventory\Rules;

use Illuminate\Support\Facades\Config;
use Kirschbaum\Monitor\ControlPoint;
use Kirschbaum\Monitor\Escalations\Escalation;
use Kirschbaum\Monitor\Inventory\Finding;
use Kirschbaum\Monitor\Inventory\Inventory;
use Kirschbaum\Monitor\Policies\Policy;

/**
 * A class in a critical namespace that is neither a control point class nor
 * calls one inline is an operation nobody declared.
 */
final class CriticalNamespaceUncontrolled implements Rule
{
    public function name(): string
    {
        return 'critical_namespace_uncontrolled';
    }

    public function check(Inventory $inventory): array
    {
        $namespaces = array_values(array_filter(Config::array('monitor.critical_namespaces', []), is_string(...)));

        if ($namespaces === []) {
            return [];
        }

        $controlled = $inventory->controlledClasses();
        $findings = [];

        foreach ($inventory->files as $file) {
            $inlineClasses = array_values(array_filter(array_map(fn (array $p): ?string => $p['class'], $file->inlinePoints)));

            foreach ($file->classes as $class) {
                if (! $this->inNamespaces($class, $namespaces) || in_array($class, $controlled, true) || in_array($class, $inlineClasses, true)) {
                    continue;
                }

                if ($this->exempt($class)) {
                    continue;
                }

                $findings[] = new Finding($this->name(), Finding::ERROR, sprintf('%s sits in a critical namespace but is not a control point and calls none', $class), null, $file->path);
            }
        }

        return $findings;
    }

    /**
     * @param  list<string>  $namespaces
     */
    private function inNamespaces(string $class, array $namespaces): bool
    {
        foreach ($namespaces as $namespace) {
            if (str_starts_with($class, rtrim($namespace, '\\').'\\')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Interfaces, traits, enums, abstract classes, escalations and policies
     * are not operations.
     */
    private function exempt(string $class): bool
    {
        if (! class_exists($class)) {
            return true;
        }

        $reflection = new \ReflectionClass($class);

        return $reflection->isAbstract()
            || $reflection->isInterface()
            || $reflection->isTrait()
            || $reflection->isEnum()
            || $reflection->isSubclassOf(ControlPoint::class)
            || $reflection->implementsInterface(Escalation::class)
            || $reflection->implementsInterface(Policy::class);
    }
}
