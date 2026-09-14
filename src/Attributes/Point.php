<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Attributes;

use Attribute;
use BackedEnum;

/**
 * Names a control point class. Everything here is readable without running
 * anything, which is what the inventory needs.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Point
{
    public string $name;

    public function __construct(
        string|BackedEnum $name,
        public ?string $profile = null,
        public ?string $domain = null,
    ) {
        $this->name = $name instanceof BackedEnum ? (string) $name->value : $name;
    }
}
