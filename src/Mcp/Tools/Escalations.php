<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Kirschbaum\Monitor\Console\Commands\OutcomesCommand;
use Kirschbaum\Monitor\Store\OutcomeStore;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class Escalations extends Tool
{
    protected string $name = 'escalations';

    protected string $description = 'Control point runs that escalated or were refused in a window, grouped by point with the exception classes seen. The quickest answer to "what failed last night". Requires the outcome store.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'since' => $schema->string()->description('How far back: 15m, 2h, 7d (default 24h)'),
            'domain' => $schema->string()->description('Only this domain'),
        ];
    }

    public function handle(Request $request, OutcomeStore $store): Response
    {
        if (! $store->enabled()) {
            return Response::error('The outcome store is disabled. Set MONITOR_STORE_ENABLED=true and run the migration; records are still in the log.');
        }

        $since = $request->get('since');
        $window = OutcomesCommand::parseSince(is_string($since) && $since !== '' ? $since : '24h');

        if (! $window instanceof Carbon) {
            return Response::error('"since" must look like 15m, 2h or 7d.');
        }

        $domain = $request->get('domain');
        $filters = ['since' => $window, 'domain' => is_string($domain) && $domain !== '' ? $domain : null];

        $rows = array_merge(
            $store->recent($filters + ['status' => 'escalated'], 500),
            $store->recent($filters + ['status' => 'refused'], 500),
        );

        $grouped = [];

        foreach ($rows as $row) {
            $point = is_string($row['point']) ? $row['point'] : 'unknown';
            $grouped[$point] ??= ['point' => $point, 'domain' => $row['domain'], 'escalated' => 0, 'refused' => 0, 'exceptions' => [], 'last_at' => $row['ended_at'], 'last_trace_id' => $row['trace_id']];
            $grouped[$point][$row['status'] === 'refused' ? 'refused' : 'escalated']++;

            if (is_string($row['exception_class'] ?? null)) {
                $grouped[$point]['exceptions'][$row['exception_class']] = ($grouped[$point]['exceptions'][$row['exception_class']] ?? 0) + 1;
            }
        }

        usort($grouped, fn (array $a, array $b): int => ($b['escalated'] + $b['refused']) <=> ($a['escalated'] + $a['refused']));

        return Response::json(['since' => $window->toIso8601String(), 'points' => $grouped]);
    }
}
