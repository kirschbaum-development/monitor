<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Kirschbaum\Monitor\StructuredLogger;
use Kirschbaum\Redactor\Facades\Redactor;

describe('LogRedactor Integration Tests', function () {
    beforeEach(function () {
        config()->set('monitor.enabled', true);
        config()->set('monitor.origin_path_wrapper', 'none');

        $this->setupLogMocking();
    });

    it('calls Redactor facade when redactor is enabled', function () {
        config()->set('monitor.redactor.enabled', true);
        config()->set('monitor.redactor.redactor_profile', 'test_profile');

        $testContext = ['user_id' => 123, 'email' => 'test@example.com'];
        $testMessage = 'Test message with sensitive data';

        Redactor::shouldReceive('redact')
            ->once()
            ->with($testContext, 'test_profile')
            ->andReturn(['user_id' => 123, 'email' => '[REDACTED]']);

        Redactor::shouldReceive('redact')
            ->once()
            ->with($testMessage, 'test_profile')
            ->andReturn('Test message with [REDACTED]');

        Log::shouldReceive('info')->once()->withAnyArgs();

        StructuredLogger::from('TestClass')->info($testMessage, $testContext);
    });

    it('does not call Redactor facade when redactor is disabled', function () {
        config()->set('monitor.redactor.enabled', false);

        $testContext = ['user_id' => 123, 'email' => 'test@example.com'];
        $testMessage = 'Test message with sensitive data';

        Redactor::shouldReceive('redact')->never();
        Log::shouldReceive('info')->once()->withAnyArgs();

        StructuredLogger::from('TestClass')->info($testMessage, $testContext);
    });

    it('uses the configured redactor profile', function () {
        config()->set('monitor.redactor.enabled', true);
        config()->set('monitor.redactor.redactor_profile', 'custom_profile');

        $testContext = ['data' => 'sensitive'];
        $testMessage = 'Test message';

        Redactor::shouldReceive('redact')
            ->once()
            ->with($testContext, 'custom_profile')
            ->andReturn($testContext);

        Redactor::shouldReceive('redact')
            ->once()
            ->with($testMessage, 'custom_profile')
            ->andReturn($testMessage);

        Log::shouldReceive('info')->once()->withAnyArgs();

        StructuredLogger::from('TestClass')->info($testMessage, $testContext);
    });

    it('defaults to default profile when not configured', function () {
        config()->set('monitor.redactor.enabled', true);
        // Don't set redactor_profile key at all to test default behavior

        $testContext = ['data' => 'sensitive'];
        $testMessage = 'Test message';

        Redactor::shouldReceive('redact')
            ->once()
            ->with($testContext, 'default')
            ->andReturn($testContext);

        Redactor::shouldReceive('redact')
            ->once()
            ->with($testMessage, 'default')
            ->andReturn($testMessage);

        Log::shouldReceive('info')->once()->withAnyArgs();

        StructuredLogger::from('TestClass')->info($testMessage, $testContext);
    });

    it('calls redactor for all log levels', function () {
        config()->set('monitor.redactor.enabled', true);
        config()->set('monitor.redactor.redactor_profile', 'default');

        $testContext = ['sensitive' => 'data'];
        $testMessage = 'Test message';

        $logLevels = ['info', 'error', 'warning', 'debug'];

        foreach ($logLevels as $level) {
            Redactor::shouldReceive('redact')
                ->once()
                ->with($testContext, 'default')
                ->andReturn($testContext);

            Redactor::shouldReceive('redact')
                ->once()
                ->with($testMessage, 'default')
                ->andReturn($testMessage);

            Log::shouldReceive($level)->once()->withAnyArgs();

            StructuredLogger::from('TestClass')->{$level}($testMessage, $testContext);
        }
    });
});
