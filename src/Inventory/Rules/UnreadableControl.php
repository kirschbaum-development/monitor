<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Inventory\Rules;

use Kirschbaum\Monitor\Contracts\Rule;
use Kirschbaum\Monitor\Inventory\Finding;
use Kirschbaum\Monitor\Inventory\Inventory;

/**
 * control() that depends on constructor arguments cannot be read statically,
 * so nothing else in the inventory can be trusted for that point.
 */
class UnreadableControl implements Rule
{
    public function name(): string
    {
        return 'unreadable_control';
    }

    public function check(Inventory $inventory): array
    {
        $findings = [];

        foreach ($inventory->classPoints() as $point) {
            if (! $point->unreadable) {
                continue;
            }

            foreach ($point->notes as $note) {
                $findings[] = new Finding($this->name(), Finding::ERROR, sprintf('"%s": %s', $point->name, $note), $point->name, $point->file, $point->line);
            }
        }

        return $findings;
    }
}
