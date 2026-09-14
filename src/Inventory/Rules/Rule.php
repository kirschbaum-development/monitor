<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Inventory\Rules;

use Kirschbaum\Monitor\Inventory\Finding;
use Kirschbaum\Monitor\Inventory\Inventory;

interface Rule
{
    public function name(): string;

    /**
     * @return list<Finding>
     */
    public function check(Inventory $inventory): array;
}
