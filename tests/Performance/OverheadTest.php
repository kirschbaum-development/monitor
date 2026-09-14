<?php

declare(strict_types=1);

use Kirschbaum\Monitor\Facades\Monitor;
use TiMacDonald\Log\LogFake;

/**
 * Guards for the cost of a control point on the hot path. Relative and
 * generous, so they say something true on any machine and flag only a real
 * regression: a policy pipeline rebuilt per attempt, redaction run twice,
 * a Context write per note.
 */
describe('control point overhead', function (): void {
    it('keeps a no-op point cheap', function (): void {
        LogFake::bind();

        $control = Monitor::control('perf.noop');
        $control->run(fn (): int => 1);

        $start = hrtime(true);

        for ($i = 0; $i < 200; $i++) {
            $control->run(fn (): int => $i);
        }

        $perRunMs = (hrtime(true) - $start) / 1e6 / 200;

        expect($perRunMs)->toBeLessThan(5.0);
    })->skip(runningWithCoverage(), 'timing is meaningless under coverage instrumentation');
});
