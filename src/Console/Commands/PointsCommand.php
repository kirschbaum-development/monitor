<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Console\Commands;

use Illuminate\Console\Command;
use Kirschbaum\Monitor\Inventory\Discovery;
use Kirschbaum\Monitor\Inventory\Reports\JsonReport;
use Kirschbaum\Monitor\Inventory\Reports\SarifReport;
use Kirschbaum\Monitor\Inventory\Reports\TableReport;
use Kirschbaum\Monitor\Store\OutcomeStore;
use Throwable;

class PointsCommand extends Command
{
    protected $signature = 'monitor:points
        {--check : Exit 1 when any rule reports an error}
        {--format=table : table, json or sarif}
        {--path=* : Directories to scan instead of the configured ones}';

    protected $description = 'List every control point and check the declarations';

    public function handle(Discovery $discovery, OutcomeStore $store): int
    {
        $paths = array_values(array_filter((array) $this->option('path'), is_string(...)));
        $inventory = $discovery->build($paths === [] ? null : $paths);

        $format = $this->option('format');

        match ($format) {
            'json' => $this->line((new JsonReport)->render($inventory)),
            'sarif' => $this->line((new SarifReport)->render($inventory, base_path())),
            default => (new TableReport)->render($this->output, $inventory, $this->lastSeen($store)),
        };

        if ($this->option('check') && $inventory->hasErrors()) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, array{ended_at: string, status: string}>
     */
    private function lastSeen(OutcomeStore $store): array
    {
        if (! $store->enabled()) {
            return [];
        }

        try {
            return $store->lastSeen();
        } catch (Throwable) {
            return [];
        }
    }
}
