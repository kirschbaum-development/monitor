<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Console;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputOption;

/**
 * make:control-point Payments/RefundCard
 *
 * Generates App\ControlPoints\Payments\RefundCard with a #[Point] attribute,
 * an empty control() to fill in, and a test that already uses Monitor::fake().
 */
final class MakeControlPointCommand extends GeneratorCommand
{
    protected $name = 'make:control-point';

    protected $description = 'Create a control point class and its test';

    protected $type = 'Control point';

    public function handle(): ?bool
    {
        $result = parent::handle();

        if ($result === false) {
            return false;
        }

        if (! $this->option('no-test')) {
            $this->writeTest();
        }

        return $result;
    }

    protected function getStub(): string
    {
        return __DIR__.'/../../stubs/control-point.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\ControlPoints';
    }

    protected function buildClass($name): string
    {
        $stub = parent::buildClass($name);

        return str_replace(
            ['{{ point }}', '{{ profile }}'],
            [$this->pointName($name), $this->profileArgument()],
            $stub,
        );
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    protected function getOptions(): array
    {
        return [
            ['point', null, InputOption::VALUE_OPTIONAL, 'The control point name (default: derived from the class)'],
            ['profile', null, InputOption::VALUE_OPTIONAL, 'The profile to start from: external, database, messaging or internal'],
            ['no-test', null, InputOption::VALUE_NONE, 'Do not generate the test'],
            ['force', 'f', InputOption::VALUE_NONE, 'Overwrite the class if it already exists'],
        ];
    }

    private function pointName(string $class): string
    {
        $given = $this->option('point');

        if (is_string($given) && $given !== '') {
            return $given;
        }

        $relative = Str::after($class, $this->getDefaultNamespace(trim($this->rootNamespace(), '\\')).'\\');
        $segments = explode('\\', $relative);
        $short = array_pop($segments);
        $domain = $segments === [] ? 'app' : Str::snake(implode('', $segments));

        return $domain.'.'.Str::snake($short);
    }

    private function profileArgument(): string
    {
        $profile = $this->option('profile');

        return is_string($profile) && $profile !== '' ? ", profile: '{$profile}'" : '';
    }

    private function writeTest(): void
    {
        $name = $this->qualifyClass($this->getNameInput());
        $relative = Str::after($name, $this->getDefaultNamespace(trim($this->rootNamespace(), '\\')).'\\');
        $path = base_path('tests/Feature/ControlPoints/'.str_replace('\\', '/', $relative).'Test.php');

        if ($this->files->exists($path) && ! $this->option('force')) {
            $this->components->warn(sprintf('Test already exists: %s', $path));

            return;
        }

        $stub = $this->files->get(__DIR__.'/../../stubs/control-point-test.stub');
        $short = class_basename($name);

        $content = str_replace(
            ['{{ class }}', '{{ fqcn }}', '{{ point }}'],
            [$short, $name, $this->pointName($name)],
            $stub,
        );

        $this->makeDirectory($path);
        $this->files->put($path, $content);
        $this->components->info(sprintf('Test [%s] created successfully.', $path));
    }
}
