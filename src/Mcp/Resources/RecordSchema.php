<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Mcp\Resources;

use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

class RecordSchema extends Resource
{
    protected string $name = 'record_schema';

    protected string $description = 'The JSON schema of every record Monitor writes to the log (monitor/1): field names, event names and status values.';

    protected string $uri = 'monitor://schema/record-1';

    protected string $mimeType = 'application/json';

    public function handle(): Response
    {
        return Response::text((string) file_get_contents(__DIR__.'/../../../resources/schema/record-1.json'));
    }
}
