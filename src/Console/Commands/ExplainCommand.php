<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Console\Commands;

use Illuminate\Console\Command;
use Kirschbaum\Monitor\Inventory\Discovery;
use Kirschbaum\Monitor\Inventory\Explainer;
use Kirschbaum\Monitor\Inventory\PointDescription;
use Kirschbaum\Monitor\Store\OutcomeStore;

class ExplainCommand extends Command
{
    protected $signature = 'monitor:explain {point : The control point name} {--json : Print the description as JSON}';

    protected $description = 'Describe one control point\'s contract in plain words';

    public function handle(Discovery $discovery, Explainer $explainer, OutcomeStore $store): int
    {
        $name = $this->argument('point');
        $point = $discovery->build()->find($name);

        if (! $point instanceof PointDescription) {
            $this->components->error(sprintf('No control point named "%s" was found.', $name));

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($point->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line($explainer->explain($point, $store->enabled() ? $store : null));

        return self::SUCCESS;
    }
}
