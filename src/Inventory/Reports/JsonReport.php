<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Inventory\Reports;

use Kirschbaum\Monitor\Inventory\Inventory;

final class JsonReport
{
    public function render(Inventory $inventory): string
    {
        return (string) json_encode($inventory->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
