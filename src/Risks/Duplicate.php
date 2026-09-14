<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Risks;

/**
 * The point already ran for this idempotency key inside the window, so this
 * run was refused before anything executed.
 */
final class Duplicate extends Risk
{
    public function __construct(public readonly string $key, public readonly ?string $originalRunId)
    {
        parent::__construct(sprintf('A run for idempotency key [%s] already exists%s.', $key, $originalRunId !== null ? " (run {$originalRunId})" : ''));
    }
}
