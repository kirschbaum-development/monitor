<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kirschbaum\Monitor\Inventory\Discovery;
use Kirschbaum\Monitor\Inventory\Explainer;
use Kirschbaum\Monitor\Inventory\PointDescription;
use Kirschbaum\Monitor\Store\OutcomeStore;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ExplainPoint extends Tool
{
    protected string $name = 'explain_point';

    protected string $description = 'Describe one control point\'s contract in plain words: where it lives, its policies, limits, risks, escalation, and its recent history when the store is enabled.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'point' => $schema->string()->description('The control point name, e.g. payment.charge')->required(),
        ];
    }

    public function handle(Request $request, Discovery $discovery, Explainer $explainer, OutcomeStore $store): Response
    {
        $name = $request->get('point');

        if (! is_string($name) || $name === '') {
            return Response::error('Pass the control point name as "point".');
        }

        $point = $discovery->build()->find($name);

        if (! $point instanceof PointDescription) {
            return Response::error(sprintf('No control point named "%s". Use list_points to see what exists.', $name));
        }

        return Response::text($explainer->explain($point, $store->enabled() ? $store : null));
    }
}
