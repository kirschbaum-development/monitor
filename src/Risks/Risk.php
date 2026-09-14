<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Risks;

use Kirschbaum\Monitor\Exceptions\MonitorException;
use RuntimeException;

/**
 * A failure Monitor itself raises inside a control point. Every risk can be
 * recovered from with recover(TheRisk::class, ...) like any other exception.
 */
abstract class Risk extends RuntimeException implements MonitorException {}
