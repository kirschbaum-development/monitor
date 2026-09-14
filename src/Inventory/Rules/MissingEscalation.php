<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Inventory\Rules;

use Kirschbaum\Monitor\Inventory\Finding;
use Kirschbaum\Monitor\Inventory\Inventory;

/**
 * The one thing a critical operation must never do is fail without anyone
 * being told. A class-form point needs an escalation or an explicit catch-all.
 */
final class MissingEscalation implements Rule
{
    public function name(): string
    {
        return 'missing_escalation';
    }

    public function check(Inventory $inventory): array
    {
        $findings = [];

        foreach ($inventory->classPoints() as $point) {
            if ($point->hasEscalation() || $point->catchAll || $point->unreadable || $point->dynamicName) {
                continue;
            }

            $findings[] = new Finding($this->name(), Finding::ERROR, sprintf('"%s" declares no escalation and no catch-all; an unexpected failure would leave silently', $point->name), $point->name, $point->file, $point->line);
        }

        return $findings;
    }
}
