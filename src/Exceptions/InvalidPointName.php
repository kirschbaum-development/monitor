<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Exceptions;

use InvalidArgumentException;

class InvalidPointName extends InvalidArgumentException implements MonitorException {}
