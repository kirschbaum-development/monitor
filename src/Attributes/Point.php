<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Attributes;

use Attribute;

/**
 * Names a control point class. Everything here is readable without running
 * anything, which is what the inventory needs.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Point
{
    public function __construct(
        public string $name,
        public ?string $profile = null,
        public ?string $domain = null,
    ) {}
}
