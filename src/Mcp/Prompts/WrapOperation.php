<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Mcp\Prompts;

use Kirschbaum\Monitor\Mcp\Resources\Guidelines;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

class WrapOperation extends Prompt
{
    protected string $name = 'wrap_operation';

    protected string $description = 'Turn an existing operation into a control point class: infer the risks from its catch blocks, pick a profile, name it, and generate the class and its test.';

    public function arguments(): array
    {
        return [
            new Argument(name: 'path', description: 'Path to the PHP file holding the operation, relative to the project root', required: true),
            new Argument(name: 'point', description: 'The control point name to use, e.g. payment.charge (optional; propose one if omitted)', required: false),
        ];
    }

    public function handle(Request $request): Response
    {
        $path = $request->get('path');
        $point = $request->get('point');
        $source = '';
        $path = is_string($path) ? $path : '';

        if ($path !== '') {
            $absolute = str_starts_with($path, '/') ? $path : base_path($path);
            $source = is_file($absolute) ? (string) file_get_contents($absolute) : '';
        }

        $ask = is_string($point) && $point !== '' ? "Name it \"{$point}\"." : 'Propose a dotted lowercase name of the form domain.operation.';

        $body = <<<MARKDOWN
        Turn the operation in `{$path}` into a control point class following these guidelines.

        {$ask} Put the class under `App\\ControlPoints\\{Domain}`. Infer the risks from the existing `catch` blocks and
        the exceptions the called code documents; each becomes a `recover()` with the value the caller expects. Pick the
        profile from what the operation does: external for outbound HTTP, database for transactional writes, messaging for
        queues and email, internal otherwise. Add an `ensure()` if the call can "succeed" with a failing result. Declare an
        escalation. Then write the Pest test using `Monitor::fake()` with one case per risk and one for an unexpected failure.

        ## Guidelines

        {$this->guidelines()}

        ## Source

        ```php
        {$source}
        ```
        MARKDOWN;

        return Response::text($body);
    }

    private function guidelines(): string
    {
        return Guidelines::content();
    }
}
