<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Mcp\Resources;

use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

final class Guidelines extends Resource
{
    protected string $name = 'guidelines';

    protected string $description = 'How control points are declared in this application: when an operation is critical, the class form, naming, profiles, risks, limits, escalation and testing. Read before adding one.';

    protected string $uri = 'monitor://guidelines';

    protected string $mimeType = 'text/markdown';

    public function handle(): Response
    {
        return Response::text(self::content());
    }

    public static function content(): string
    {
        return (string) file_get_contents(__DIR__.'/../../../.ai/guidelines/core.blade.php');
    }
}
