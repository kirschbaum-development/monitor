<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Inventory;

use Kirschbaum\Monitor\Control;
use Kirschbaum\Monitor\Facades\Monitor as MonitorFacade;
use Kirschbaum\Monitor\Monitor;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

/**
 * Reads a PHP file without executing it: the classes it declares, and every
 * Monitor::control('name') or new Control('name') it contains.
 */
class AstScanner
{
    public function scan(string $path): ScannedFile
    {
        $code = is_file($path) ? file_get_contents($path) : false;

        if ($code === false) {
            return new ScannedFile($path, [], []);
        }

        try {
            $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($code) ?? [];
        } catch (Error) {
            return new ScannedFile($path, [], []);
        }

        $visitor = new class extends NodeVisitorAbstract
        {
            /** @var list<string> */
            public array $classes = [];

            /** @var list<array{name: string|null, line: int, class: string|null}> */
            public array $points = [];

            /** @var list<string|null> */
            private array $classStack = [];

            public function enterNode(Node $node): null
            {
                if ($node instanceof Node\Stmt\ClassLike) {
                    $name = isset($node->namespacedName) ? $node->namespacedName->toString() : null;

                    if ($name !== null && ($node instanceof Node\Stmt\Class_ || $node instanceof Node\Stmt\Enum_ || $node instanceof Node\Stmt\Trait_ || $node instanceof Node\Stmt\Interface_)) {
                        $this->classes[] = $name;
                    }

                    $this->classStack[] = $name;
                }

                if ($node instanceof Node\Expr\StaticCall && $node->name instanceof Node\Identifier && $node->name->toString() === 'control' && $node->class instanceof Node\Name) {
                    $class = $node->class->toString();

                    if (in_array($class, [MonitorFacade::class, Monitor::class], true)) {
                        $this->points[] = ['name' => $this->literal($node->args[0] ?? null), 'line' => $node->getStartLine(), 'class' => $this->currentClass()];
                    }
                }

                if ($node instanceof Node\Expr\New_ && $node->class instanceof Node\Name && $node->class->toString() === Control::class) {
                    $this->points[] = ['name' => $this->literal($node->args[0] ?? null), 'line' => $node->getStartLine(), 'class' => $this->currentClass()];
                }

                return null;
            }

            public function leaveNode(Node $node): null
            {
                if ($node instanceof Node\Stmt\ClassLike) {
                    array_pop($this->classStack);
                }

                return null;
            }

            private function currentClass(): ?string
            {
                return $this->classStack === [] ? null : $this->classStack[array_key_last($this->classStack)];
            }

            private function literal(?Node $arg): ?string
            {
                return $arg instanceof Node\Arg && $arg->value instanceof Node\Scalar\String_ ? $arg->value->value : null;
            }
        };

        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver);
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return new ScannedFile($path, $visitor->classes, $visitor->points);
    }
}
