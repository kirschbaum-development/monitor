<?php

declare(strict_types=1);

namespace Workbench\Monitor\ControlPoints\Payments;

use Kirschbaum\Monitor\Control;
use Kirschbaum\Monitor\Facades\Monitor;

/**
 * A plain class that uses inline points, one literal and one computed.
 */
final class InlineCharger
{
    public function charge(): string
    {
        return Monitor::control('payment.inline', $this)->run(fn (): string => 'ok');
    }

    public function direct(): string
    {
        return (new Control('payment.direct', $this))->run(fn (): string => 'ok');
    }

    public function dynamic(string $name): string
    {
        return Monitor::control($name, $this)->run(fn (): string => 'ok');
    }
}
