<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Exceptions;

use InvalidArgumentException;

class InvalidTraceId extends InvalidArgumentException implements MonitorException {}
