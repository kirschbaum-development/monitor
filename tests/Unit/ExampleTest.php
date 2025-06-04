<?php

namespace Tests\Unit;

use Kirschbaum\Monitor\Monitor;

it('can be instantiated', function () {
    $monitor = new Monitor;

    expect($monitor)->toBeInstanceOf(Monitor::class);
});
