<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Inventory\Rules;

use Kirschbaum\Monitor\Contracts\Rule;
use Kirschbaum\Monitor\Inventory\Finding;
use Kirschbaum\Monitor\Inventory\Inventory;

/**
 * A point whose name is computed cannot be inventoried, tested by name or
 * found in the records by anyone reading the code.
 */
class DynamicName implements Rule
{
    public function name(): string
    {
        return 'dynamic_name';
    }

    public function check(Inventory $inventory): array
    {
        $findings = [];

        foreach ($inventory->points as $point) {
            if (! $point->dynamicName || $point->isClassForm()) {
                continue;
            }

            $findings[] = new Finding($this->name(), Finding::WARNING, sprintf('a control point in %s takes its name from an expression; use a string literal', $point->origin), null, $point->file, $point->line);
        }

        return $findings;
    }
}
