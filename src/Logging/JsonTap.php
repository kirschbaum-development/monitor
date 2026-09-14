<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Logging;

use Illuminate\Log\Logger;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Logger as Monolog;

/**
 * Makes a channel write newline-delimited JSON with Monitor's record fields
 * at the top level. Add it to any channel's "tap" list.
 */
final class JsonTap
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if (! $monolog instanceof Monolog) {
            return;
        }

        foreach ($monolog->getHandlers() as $handler) {
            if ($handler instanceof FormattableHandlerInterface) {
                $handler->setFormatter(new RecordFormatter);
            }
        }
    }
}
