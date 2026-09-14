<?php

declare(strict_types=1);

namespace Workbench\Monitor\Support;

use RuntimeException;

final class CardDeclined extends RuntimeException
{
    public function __construct(public readonly string $declineCode = 'do_not_honor')
    {
        parent::__construct("Card declined: {$declineCode}");
    }
}
