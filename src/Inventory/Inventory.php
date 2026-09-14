<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Inventory;

/**
 * Every control point the scan found, every class it saw, and the findings
 * the rules raised.
 */
class Inventory
{
    /**
     * @param  list<PointDescription>  $points
     * @param  list<ScannedFile>  $files
     * @param  list<Finding>  $findings
     */
    public function __construct(
        public readonly array $points,
        public readonly array $files,
        private array $findings = [],
    ) {}

    /**
     * @return list<Finding>
     */
    public function findings(): array
    {
        return $this->findings;
    }

    public function addFinding(Finding $finding): void
    {
        $this->findings[] = $finding;
    }

    public function hasErrors(): bool
    {
        return array_any($this->findings, fn (Finding $f): bool => $f->isError());
    }

    /**
     * @return list<PointDescription>
     */
    public function classPoints(): array
    {
        return array_values(array_filter($this->points, fn (PointDescription $p): bool => $p->isClassForm()));
    }

    public function find(string $name): ?PointDescription
    {
        foreach ($this->points as $point) {
            if ($point->name === $name) {
                return $point;
            }
        }

        return null;
    }

    /**
     * Fully qualified class names of every class-form point.
     *
     * @return list<string>
     */
    public function controlledClasses(): array
    {
        $classes = [];

        foreach ($this->points as $point) {
            $classes[] = $point->origin;
        }

        return array_values(array_unique($classes));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'points' => array_map(fn (PointDescription $p): array => $p->toArray(), $this->points),
            'findings' => array_map(fn (Finding $f): array => $f->toArray(), $this->findings),
        ];
    }
}
