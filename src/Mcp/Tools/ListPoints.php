<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kirschbaum\Monitor\Inventory\Discovery;
use Kirschbaum\Monitor\Inventory\Finding;
use Kirschbaum\Monitor\Inventory\PointDescription;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListPoints extends Tool
{
    protected string $name = 'list_points';

    protected string $description = 'List every control point in the application with its domain, form, policies, risks and escalation, plus any findings from the declaration rules.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'domain' => $schema->string()->description('Only points in this domain'),
        ];
    }

    public function handle(Request $request, Discovery $discovery): Response
    {
        $inventory = $discovery->build();
        $domain = $request->get('domain');
        $filtered = is_string($domain) && $domain !== '';

        $points = array_values(array_filter(
            $inventory->points,
            fn (PointDescription $p): bool => ! $filtered || $p->domain === $domain,
        ));
        $names = array_map(fn (PointDescription $p): string => $p->name, $points);

        $findings = array_values(array_filter(
            $inventory->findings(),
            fn (Finding $f): bool => ! $filtered || $f->point === null || in_array($f->point, $names, true),
        ));

        return Response::json([
            'points' => array_map(fn (PointDescription $p): array => $p->toArray(), $points),
            'findings' => array_map(fn (Finding $f): array => $f->toArray(), $findings),
        ]);
    }
}
