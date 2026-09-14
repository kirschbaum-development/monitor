<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Inventory;

final readonly class Finding
{
    public const string ERROR = 'error';

    public const string WARNING = 'warning';

    public function __construct(
        public string $rule,
        public string $level,
        public string $message,
        public ?string $point = null,
        public ?string $file = null,
        public ?int $line = null,
    ) {}

    public function isError(): bool
    {
        return $this->level === self::ERROR;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rule' => $this->rule,
            'level' => $this->level,
            'message' => $this->message,
            'point' => $this->point,
            'file' => $this->file,
            'line' => $this->line,
        ];
    }
}
