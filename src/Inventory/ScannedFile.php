<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Inventory;

/**
 * What one PHP file contributes: the classes it declares and the inline
 * control points it calls, with the class each call sits in.
 */
final readonly class ScannedFile
{
    /**
     * @param  list<string>  $classes  fully qualified class names declared in the file
     * @param  list<array{name: string|null, line: int, class: string|null}>  $inlinePoints
     */
    public function __construct(
        public string $path,
        public array $classes,
        public array $inlinePoints,
    ) {}
}
