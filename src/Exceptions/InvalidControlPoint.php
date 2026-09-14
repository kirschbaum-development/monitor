<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Exceptions;

use InvalidArgumentException;

class InvalidControlPoint extends InvalidArgumentException implements MonitorException {}
