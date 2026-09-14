<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Inventory;

use Illuminate\Support\Facades\Config;
use Kirschbaum\Monitor\Contracts\Rule;
use Kirschbaum\Monitor\Control;
use Kirschbaum\Monitor\ControlPoint;
use Kirschbaum\Monitor\Support\Domain;
use Kirschbaum\Monitor\Support\Lists;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * Builds the inventory: scans the configured paths, describes every class-form
 * point fully and every inline point by name, then runs the rules.
 */
class Discovery
{
    public function __construct(private readonly AstScanner $scanner) {}

    /**
     * @param  list<string>|null  $paths  absolute or relative to base_path(); defaults to config
     * @param  list<Rule>|null  $rules  defaults to the configured rules
     */
    public function build(?array $paths = null, ?array $rules = null): Inventory
    {
        $files = $this->scan($paths ?? $this->configuredPaths());
        $points = [];

        foreach ($files as $file) {
            foreach ($file->classes as $class) {
                if (! is_a($class, ControlPoint::class, true) || (new \ReflectionClass($class))->isAbstract()) {
                    continue;
                }

                $points[] = $this->describeClass($class, $file->path);
            }

            foreach ($file->inlinePoints as $inline) {
                $points[] = $this->describeInline($inline, $file->path);
            }
        }

        $inventory = new Inventory($points, $files);

        foreach ($rules ?? Rules\Rules::configured() as $rule) {
            foreach ($rule->check($inventory) as $finding) {
                $inventory->addFinding($finding);
            }
        }

        return $inventory;
    }

    /**
     * @param  list<string>  $paths
     * @return list<ScannedFile>
     */
    public function scan(array $paths): array
    {
        $directories = [];

        foreach ($paths as $path) {
            $absolute = str_starts_with($path, '/') ? $path : base_path($path);

            if (is_dir($absolute)) {
                $directories[] = $absolute;
            }
        }

        if ($directories === []) {
            return [];
        }

        $files = [];

        foreach (Finder::create()->files()->name('*.php')->in($directories)->sortByName() as $file) {
            $files[] = $this->scanner->scan($file->getRealPath());
        }

        return $files;
    }

    /**
     * @return list<string>
     */
    public function configuredPaths(): array
    {
        $paths = Config::array('monitor.discovery.paths', ['app']);

        return array_values(array_filter($paths, is_string(...)));
    }

    /**
     * @param  class-string<ControlPoint>  $class
     */
    private function describeClass(string $class, string $file): PointDescription
    {
        try {
            $described = $class::describe();
        } catch (Throwable $e) {
            return new PointDescription(
                name: $class,
                form: 'class',
                origin: $class,
                domain: Domain::resolve($class),
                file: $file,
                line: null,
                notes: ['could not be described: '.$e->getMessage()],
                dynamicName: true,
            );
        }

        return new PointDescription(
            name: is_string($described['point']) ? $described['point'] : $class,
            form: 'class',
            origin: $class,
            domain: is_string($described['domain']) ? $described['domain'] : Domain::resolve($class),
            file: $file,
            line: (new \ReflectionClass($class))->getStartLine() ?: null,
            profile: is_string($described['profile']) ? $described['profile'] : null,
            policies: Lists::ofArrays($described['policies'] ?? null),
            limits: Lists::ofArrays($described['limits'] ?? null),
            risks: is_array($described['risks']) ? array_values(array_filter($described['risks'], is_string(...))) : [],
            catchAll: (bool) ($described['catch_all'] ?? false),
            escalation: is_string($described['escalation']) ? $described['escalation'] : null,
            notes: is_array($described['notes']) ? array_values(array_filter($described['notes'], is_string(...))) : [],
            unreadable: (bool) ($described['unreadable'] ?? false),
        );
    }

    /**
     * @param  array{name: string|null, line: int, class: string|null}  $inline
     */
    private function describeInline(array $inline, string $file): PointDescription
    {
        $origin = $inline['class'] ?? Control::class;

        return new PointDescription(
            name: $inline['name'] ?? '(dynamic)',
            form: 'inline',
            origin: $origin,
            domain: Domain::resolve($origin),
            file: $file,
            line: $inline['line'],
            notes: ['inline points are inventoried by name; their closures are not inspected'],
            dynamicName: $inline['name'] === null,
        );
    }
}
