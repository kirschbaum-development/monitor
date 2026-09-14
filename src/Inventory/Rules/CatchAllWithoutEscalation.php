<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Inventory\Rules;

use Kirschbaum\Monitor\Inventory\Finding;
use Kirschbaum\Monitor\Inventory\Inventory;

/**
 * recover(Throwable::class) with no escalation swallows everything. It is
 * usually a risk analysis that has not been done.
 */
final class CatchAllWithoutEscalation implements Rule
{
    public function name(): string
    {
        return 'catch_all_without_escalation';
    }

    public function check(Inventory $inventory): array
    {
        $findings = [];

        foreach ($inventory->classPoints() as $point) {
            if (! $point->catchAll || $point->hasEscalation()) {
                continue;
            }

            $findings[] = new Finding($this->name(), Finding::WARNING, sprintf('"%s" recovers from Throwable with no escalation; every failure is swallowed', $point->name), $point->name, $point->file, $point->line);
        }

        return $findings;
    }
}
