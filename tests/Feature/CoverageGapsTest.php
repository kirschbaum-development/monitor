<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Kirschbaum\Monitor\Breaker\BreakerConfig;
use Kirschbaum\Monitor\Facades\Monitor;
use Kirschbaum\Monitor\Inventory\Discovery;
use Kirschbaum\Monitor\Inventory\Explainer;
use Kirschbaum\Monitor\Inventory\Finding;
use Kirschbaum\Monitor\Mcp\ResponseRedactor;
use Kirschbaum\Monitor\Policies\Breaker;
use Kirschbaum\Monitor\Store\OutcomeStore;
use Kirschbaum\Monitor\Support\Lists;
use Kirschbaum\Monitor\Support\Profiles;
use Kirschbaum\Monitor\Testing\Expectations;
use Kirschbaum\Redactor\Facades\Redactor;
use Laravel\Mcp\Transport\JsonRpcResponse;
use Tests\Fixtures\Fatal;
use Workbench\Monitor\ControlPoints\Filings\Unnamed;

/**
 * Edges the feature suites do not reach on their own.
 */
describe('edges', function (): void {
    it('lets a control change its origin and report escalation presence', function (): void {
        $control = Monitor::control('a.b')->from('App\\Services\\Payments\\X');

        expect($control->origin())->toBe('App\\Services\\Payments\\X')
            ->and($control->hasEscalation())->toBeFalse()
            ->and($control->escalate(fn (): null => null)->hasEscalation())->toBeTrue()
            ->and(Breaker::named('stripe')->name())->toBe('stripe');
    });

    it('caps the failure window even while the circuit is open', function (): void {
        $config = new BreakerConfig(after: 2, within: 60, for: 10);

        Monitor::breaker()->recordFailure('cap', $config);
        Monitor::breaker()->recordFailure('cap', $config);
        Monitor::breaker()->recordFailure('cap', $config);

        expect(Monitor::breaker()->state('cap')->failureCount())->toBe(2);
    });

    it('treats non-array profile exception lists as empty and empty writes as nothing', function (): void {
        config()->set('monitor.profiles.odd', ['retry' => ['times' => 1, 'on' => 'not-a-list']]);

        expect(Profiles::policies('odd', 'x.y')['retry']->describe()['on'])->toBe([])
            ->and(resolve(OutcomeStore::class)->write([]))->toBe(0)
            ->and(Lists::ofArrays('nope'))->toBe([])
            ->and(Lists::ofArrays([1, ['a' => 1], 'x']))->toBe([['a' => 1]]);
    });

    it('registers the fluent completeness expectation on a passing namespace', function (): void {
        config()->set('monitor.discovery.paths', [realpath(__DIR__.'/../../workbench/app')]);
        config()->set('monitor.inventory.rules', ['duplicate_names' => false, 'name_pattern' => false, 'missing_escalation' => false, 'unreadable_control' => false]);
        Expectations::register();

        expect('Workbench\\Monitor\\ControlPoints\\Payments')->toHaveCompleteControlPoints();
    });
});

describe('inventory edges', function (): void {
    beforeEach(function (): void {
        config()->set('monitor.discovery.paths', [realpath(__DIR__.'/../../workbench/app')]);
        config()->set('monitor.critical_namespaces', ['Workbench\\Monitor\\ControlPoints']);
    });

    it('records a class that cannot be described and one that cannot be loaded', function (): void {
        $inventory = resolve(Discovery::class)->build();

        $unnamed = $inventory->find(Unnamed::class);

        expect($unnamed)->not->toBeNull()
            ->and($unnamed->dynamicName)->toBeTrue()
            ->and($unnamed->notes[0])->toContain('could not be described')
            ->and($inventory->find('payment.direct')?->form)->toBe('inline');

        $uncontrolled = array_filter($inventory->findings(), fn (Finding $f): bool => $f->rule === 'critical_namespace_uncontrolled');

        expect(implode(' ', array_map(fn (Finding $f): string => $f->message, $uncontrolled)))->not->toContain('GhostFiling');
    });

    it('explains an attempts limit and survives a store that cannot be read', function (): void {
        config()->set('monitor.records.store.enabled', true);
        $point = resolve(Discovery::class)->build()->find('payment.charge');

        $text = resolve(Explainer::class)->explain($point, resolve(OutcomeStore::class));

        expect($text)->toContain('at most 3 attempt(s)')->toContain('History: the store could not be read.');
    });

    it('lists points when the store is enabled but its table is missing', function (): void {
        config()->set('monitor.records.store.enabled', true);

        $this->artisan('monitor:points')->expectsOutputToContain('payment.charge')->assertSuccessful();
    });

    it('warns instead of overwriting an existing test', function (): void {
        $class = app_path('ControlPoints/Payments/Existing.php');
        $test = base_path('tests/Feature/ControlPoints/Payments/ExistingTest.php');
        File::ensureDirectoryExists(dirname($test));
        File::put($test, '<?php // keep');

        try {
            $this->artisan('make:control-point', ['name' => 'Payments/Existing'])->expectsOutputToContain('Test already exists')->assertSuccessful();

            expect(File::get($test))->toBe('<?php // keep');
        } finally {
            File::delete([$class, $test]);
            File::deleteDirectory(app_path('ControlPoints'));
            File::deleteDirectory(base_path('tests/Feature/ControlPoints'));
        }
    });
});

describe('response redactor edges', function (): void {
    it('passes everything through without a profile', function (): void {
        $redactor = new ResponseRedactor(null);
        $response = JsonRpcResponse::result('1', ['content' => [['type' => 'text', 'text' => '{"password":"x"}']]]);

        expect($redactor->redact($response))->toBe($response)
            ->and($redactor->data(['password' => 'x']))->toBe(['password' => 'x']);
    });

    it('redacts streamed notifications, structured content and iterables', function (): void {
        $redactor = new ResponseRedactor('observability');

        $notification = JsonRpcResponse::notification('notifications/message', ['content' => [['type' => 'text', 'text' => '{"password":"hunter2"}']]]);
        $structured = JsonRpcResponse::result('2', ['content' => [], 'structuredContent' => ['password' => 'hunter2', 'n' => 1]]);

        $out = iterator_to_array($redactor->redact((function () use ($notification, $structured) {
            yield $notification;
            yield $structured;
        })()));

        expect($out[0]->content['params']['content'][0]['text'])->toContain('[REDACTED]')
            ->and($out[1]->content['result']['structuredContent'])->toBe(['password' => '[REDACTED]', 'n' => 1]);
    });

    it('replaces data it cannot redact', function (): void {
        Redactor::shouldReceive('inspect')->andThrow(new Fatal('broken profile'));

        expect((new ResponseRedactor('observability'))->data(['a' => 1]))->toBe(['redaction' => 'failed']);
    });
});
