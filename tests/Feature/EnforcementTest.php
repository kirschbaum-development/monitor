<?php

declare(strict_types=1);

use Kirschbaum\Monitor\Testing\Expectations;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    config()->set('monitor.discovery.paths', [realpath(__DIR__.'/../../workbench/app')]);
});

describe('pest expectations', function (): void {
    it('registers toBeControlled and toHaveCompleteControlPoints', function (): void {
        Expectations::register();

        expect('Workbench\\Monitor\\ControlPoints\\Payments')->toBeControlled();
        expect(fn () => expect('Workbench\\Monitor\\ControlPoints\\Filings')->toBeControlled())
            ->toThrow(AssertionFailedError::class, 'Uncontrolled operations in Workbench\\Monitor\\ControlPoints\\Filings');

        expect(fn () => expect('Workbench\\Monitor\\ControlPoints\\Payments')->toHaveCompleteControlPoints())
            ->toThrow(AssertionFailedError::class, 'declares no escalation');
    });

    it('passes completeness for a namespace whose points are complete', function (): void {
        config()->set('monitor.inventory.rules', ['duplicate_names' => false, 'name_pattern' => false, 'missing_escalation' => false, 'unreadable_control' => false]);

        Expectations::assertComplete('Workbench\\Monitor\\ControlPoints\\Payments');

        expect(true)->toBeTrue();
    });

    it('rejects a non-string subject', function (): void {
        Expectations::register();

        expect(fn () => expect(42)->toBeControlled())->toThrow(InvalidArgumentException::class);
    });

    it('restores the critical namespaces it borrowed', function (): void {
        config()->set('monitor.critical_namespaces', ['Keep\\Me']);

        Expectations::assertControlled('Workbench\\Monitor\\ControlPoints\\Payments');

        expect(config('monitor.critical_namespaces'))->toBe(['Keep\\Me']);
    });
});

describe('phpstan rule', function (): void {
    it('flags the uncontrolled fixture and nothing else', function (): void {
        $process = new Process([
            PHP_BINARY, realpath(__DIR__.'/../../vendor/bin/phpstan'), 'analyse',
            '--configuration='.realpath(__DIR__.'/../Fixtures/PhpStan/critical.neon'),
            '--error-format=json', '--no-progress', '--memory-limit=1G',
        ], realpath(__DIR__.'/../..'));
        $process->run();

        $report = json_decode($process->getOutput(), true);

        $flagged = [];

        foreach ($report['files'] ?? [] as $file => $entry) {
            foreach ($entry['messages'] as $message) {
                if ($message['identifier'] === 'monitor.uncontrolled') {
                    $flagged[basename($file)] = $message['message'];
                }
            }
        }

        expect($flagged)->toHaveCount(2)->toHaveKey('Uncontrolled.php')->toHaveKey('Misnamed.php')
            ->and($flagged['Uncontrolled.php'])->toContain('Uncontrolled sits in a critical namespace');
    })->skip(! is_file(__DIR__.'/../../vendor/bin/phpstan'), 'phpstan is not installed');
});
