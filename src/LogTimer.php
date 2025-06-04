<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor;

class LogTimer
{
    protected float $start;

    public function __construct()
    {
        $this->start = microtime(true);
    }

    public function elapsed(): int
    {
        return (int) round((microtime(true) - $this->start) * 1000); // in ms
    }
}
