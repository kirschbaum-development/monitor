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

class RecentOutcomes extends Tool
{
    protected string $name = 'outcomes';

    protected string $description = 'Recent control point outcomes from the store, newest first: status, attempts, duration, exception, trace id and context. Requires the outcome store to be enabled.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'point' => $schema->string()->description('Only this control point'),
            'domain' => $schema->string()->description('Only this domain'),
            'status' => $schema->string()->description('succeeded, recovered, escalated or refused'),
            'trace' => $schema->string()->description('Only this trace id'),
            'since' => $schema->string()->description('How far back: 15m, 2h, 7d (default 24h)'),
            'limit' => $schema->integer()->description('Rows to return (default 50, max 500)'),
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

        $limit = $request->get('limit');

        $rows = $store->recent([
            'point' => $this->string($request->get('point')),
            'domain' => $this->string($request->get('domain')),
            'status' => $this->string($request->get('status')),
            'trace' => $this->string($request->get('trace')),
            'since' => $window,
        ], min(500, max(1, is_numeric($limit) ? (int) $limit : 50)));

        return Response::json(['since' => $window->toIso8601String(), 'count' => count($rows), 'outcomes' => $rows]);
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
