<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Console;

use Illuminate\Console\Command;
use Kirschbaum\Monitor\Store\OutcomeStore;

final class PruneCommand extends Command
{
    protected $signature = 'monitor:prune {--days= : Delete outcomes older than this many days (default: the configured retention)}';

    protected $description = 'Delete stored outcomes past the retention period';

    public function handle(OutcomeStore $store): int
    {
        if (! $store->enabled()) {
            $this->components->warn('The outcome store is disabled; nothing to prune.');

            return self::SUCCESS;
        }

        $days = $this->option('days');
        $deleted = $store->prune(is_numeric($days) ? max(1, (int) $days) : null);

        $this->components->info("Pruned {$deleted} outcome(s).");

        return self::SUCCESS;
    }
}
