<?php

declare(strict_types=1);

namespace Tests\Fixtures;

enum PointNames: string
{
    case Charge = 'payment.charge';
    case Refund = 'payment.refund';
}
