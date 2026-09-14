<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Exceptions;

use Throwable;

/**
 * Every exception the package throws, so callers can catch them as one.
 */
interface MonitorException extends Throwable {}
