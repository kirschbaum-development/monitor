<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Analysis\PhpStan;

use Kirschbaum\Monitor\Contracts\Escalation;
use Kirschbaum\Monitor\Contracts\Policy;
use Kirschbaum\Monitor\Control;
use Kirschbaum\Monitor\ControlPoint;
use Kirschbaum\Monitor\Facades\Monitor as MonitorFacade;
use Kirschbaum\Monitor\Monitor;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Classes in the configured critical namespaces must be control point classes
 * or call Monitor::control() somewhere in their body.
 *
 *     # phpstan.neon
 *     includes:
 *         - vendor/kirschbaum-development/monitor/extension.neon
 *     parameters:
 *         monitor:
 *             criticalNamespaces:
 *                 - App\ControlPoints
 *                 - App\Services\Payments
 *
 * @implements Rule<InClassNode>
 */
class CriticalNamespaceRule implements Rule
{
    /**
     * @param  list<string>  $namespaces
     */
    public function __construct(private readonly array $namespaces) {}

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $reflection = $node->getClassReflection();
        $original = $node->getOriginalNode();

        if ($this->namespaces === [] || ! $original instanceof Node\Stmt\Class_) {
            return [];
        }

        $class = $reflection->getName();

        if (! $this->inCriticalNamespace($class) || $reflection->isAbstract() || $reflection->isAnonymous()) {
            return [];
        }

        if ($reflection->isSubclassOf(ControlPoint::class) || $reflection->implementsInterface(Escalation::class) || $reflection->implementsInterface(Policy::class)) {
            return [];
        }

        if ($this->callsControlInline($original)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf('%s sits in a critical namespace but is not a control point and calls none.', $class))
                ->identifier('monitor.uncontrolled')
                ->tip('Extend Kirschbaum\Monitor\ControlPoint, or wrap the operation in Monitor::control().')
                ->build(),
        ];
    }

    private function inCriticalNamespace(string $class): bool
    {
        foreach ($this->namespaces as $namespace) {
            if (str_starts_with($class, rtrim($namespace, '\\').'\\')) {
                return true;
            }
        }

        return false;
    }

    private function callsControlInline(Node\Stmt\Class_ $node): bool
    {
        $calls = (new NodeFinder)->find($node->stmts, function (Node $n): bool {
            if ($n instanceof Node\Expr\StaticCall && $n->name instanceof Node\Identifier && $n->name->toString() === 'control' && $n->class instanceof Node\Name) {
                return in_array($n->class->toString(), [MonitorFacade::class, Monitor::class, 'Monitor'], true);
            }

            return $n instanceof Node\Expr\New_ && $n->class instanceof Node\Name && $n->class->toString() === Control::class;
        });

        return $calls !== [];
    }
}
