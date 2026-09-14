<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Inventory\Rules;

use Kirschbaum\Monitor\Contracts\Rule;
use Kirschbaum\Monitor\Inventory\Finding;
use Kirschbaum\Monitor\Inventory\Inventory;
use Kirschbaum\Monitor\Inventory\PointDescription;

/**
 * A name is the join key across code, records and tests; two points sharing
 * one cannot be told apart anywhere downstream.
 */
class DuplicateNames implements Rule
{
    public function name(): string
    {
        return 'duplicate_names';
    }

    public function check(Inventory $inventory): array
    {
        $byName = [];

        foreach ($inventory->points as $point) {
            if ($point->dynamicName) {
                continue;
            }

            $byName[$point->name][] = $point;
        }

        $findings = [];

        foreach ($byName as $name => $points) {
            if (count($points) < 2) {
                continue;
            }

            $where = implode(', ', array_map(fn (PointDescription $p): string => $p->origin.($p->line !== null ? ':'.$p->line : ''), $points));

            $findings[] = new Finding($this->name(), Finding::ERROR, sprintf('"%s" is declared %d times: %s', $name, count($points), $where), (string) $name, $points[0]->file, $points[0]->line);
        }

        return $findings;
    }
}
