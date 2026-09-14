<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Inventory\Rules;

use Kirschbaum\Monitor\Inventory\Finding;
use Kirschbaum\Monitor\Inventory\Inventory;
use Kirschbaum\Monitor\Support\PointName;

final class NamePattern implements Rule
{
    public function name(): string
    {
        return 'name_pattern';
    }

    public function check(Inventory $inventory): array
    {
        $findings = [];

        foreach ($inventory->points as $point) {
            if ($point->dynamicName || PointName::isValid($point->name)) {
                continue;
            }

            $findings[] = new Finding($this->name(), Finding::ERROR, sprintf('"%s" does not match the configured point name pattern', $point->name), $point->name, $point->file, $point->line);
        }

        return $findings;
    }
}
