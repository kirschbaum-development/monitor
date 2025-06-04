<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Taps;

use Illuminate\Log\Logger;
use Kirschbaum\Monitor\Formatters\StructuredJsonFormatter;
use Monolog\Handler\AbstractProcessingHandler;

class StructuredLoggingTap
{
    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getHandlers() as $handler) {
            if ($handler instanceof AbstractProcessingHandler) {
                $handler->setFormatter(new StructuredJsonFormatter);
            }
        }
    }
}
