<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Mcp;

use Illuminate\Support\Facades\Config;
use Kirschbaum\Monitor\Mcp\Prompts\WrapOperation;
use Kirschbaum\Monitor\Mcp\Resources\Guidelines;
use Kirschbaum\Monitor\Mcp\Resources\RecordSchema;
use Kirschbaum\Monitor\Mcp\Tools\Escalations;
use Kirschbaum\Monitor\Mcp\Tools\ExplainPoint;
use Kirschbaum\Monitor\Mcp\Tools\ListPoints;
use Kirschbaum\Monitor\Mcp\Tools\RecentOutcomes;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

/**
 * A read-only server that lets an agent inspect the application's control
 * points and what happened at them. Started with `php artisan mcp:start monitor`.
 */
class MonitorServer extends Server
{
    protected string $name = 'Monitor';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
        Control points are the operations in this application where a failure matters. Each one has a name like
        payment.charge, a domain, declared risks with corrections, policies (retry, transaction, breaker), limits,
        and an escalation. Use list_points to see them all, explain_point for one contract, outcomes and escalations
        to see what happened recently, and read the guidelines resource before adding a new one.
        MARKDOWN;

    protected array $tools = [
        ListPoints::class,
        ExplainPoint::class,
        RecentOutcomes::class,
        Escalations::class,
    ];

    protected array $resources = [
        RecordSchema::class,
        Guidelines::class,
    ];

    protected array $prompts = [
        WrapOperation::class,
    ];

    /**
     * Every response passes through the redactor, JSON aware.
     *
     * @return iterable<JsonRpcResponse>|JsonRpcResponse
     */
    protected function runMethodHandle(JsonRpcRequest $request, ServerContext $context): iterable|JsonRpcResponse
    {
        $profile = Config::get('monitor.records.redaction');

        return (new ResponseRedactor(is_string($profile) && $profile !== '' ? $profile : null))
            ->redact(parent::runMethodHandle($request, $context));
    }
}
