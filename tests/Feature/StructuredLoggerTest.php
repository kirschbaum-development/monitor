<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Kirschbaum\Monitor\StructuredLogger;

it('resolves origin from string with prefix and separator', function () {
    config()->set('monitor.prefix', 'Monitor');
    config()->set('monitor.origin_separator', ':');
    config()->set('monitor.origin_path_wrapper', 'none');

    $logger = StructuredLogger::from('TestOrigin');

    expect($logger->getOrigin())->toBe('Monitor:TestOrigin');
});

it('resolves origin with replacers and separator', function () {
    config()->set('monitor.prefix', null);
    config()->set('monitor.origin_separator', ':');
    config()->set('monitor.origin_path_replacers', [
        'App\\' => 'Monitor\\',
    ]);
    config()->set('monitor.origin_path_wrapper', 'none');

    $logger = StructuredLogger::from('App\\Something\\Example');

    expect($logger->getOrigin())->toBe('Monitor:Something:Example');
});

it('applies origin wrapper from config', function () {
    config()->set('monitor.prefix', 'Src');
    config()->set('monitor.origin_separator', ':');
    config()->set('monitor.origin_path_wrapper', 'curly');

    $logger = StructuredLogger::from('Logger');

    expect($logger->getOrigin())->toBe('{Src:Logger}');
});

it('resolves origin from object', function () {
    config()->set('monitor.prefix', null);
    config()->set('monitor.origin_path_wrapper', 'none');

    $obj = new class {};
    $logger = StructuredLogger::from($obj);

    expect($logger->getOrigin())->toContain('class@anonymous');
    expect($logger->getOrigin())->toContain('.php');
});

it('uses empty origin when given empty input', function () {
    config()->set('monitor.prefix', null);
    config()->set('monitor.origin_path_wrapper', 'none');

    $logger = StructuredLogger::from('');
    expect($logger->getOrigin())->toBe('');
});

it('handles various empty and whitespace inputs correctly', function () {
    config()->set('monitor.prefix', 'Test');
    config()->set('monitor.origin_path_wrapper', 'square');

    // Empty string gets wrapped but remains semantically empty
    $logger1 = StructuredLogger::from('');
    expect($logger1->getOrigin())->toBe('[]');

    // Whitespace should be processed normally (not treated as empty)
    $logger2 = StructuredLogger::from(' ');
    expect($logger2->getOrigin())->toBe('[Test: ]');

    // Single spaces should not be treated as empty
    $logger3 = StructuredLogger::from('  ');
    expect($logger3->getOrigin())->toBe('[Test:  ]');

    // Test with no wrapper - empty should stay empty
    config()->set('monitor.origin_path_wrapper', 'none');
    $logger4 = StructuredLogger::from('');
    expect($logger4->getOrigin())->toBe('');
});

it('logs with enriched context', function () {
    config()->set('monitor.origin_path_wrapper', 'square');

    Log::shouldReceive('info')
        ->once()
        ->withArgs(function ($message, $context) {
            if ($message !== '[Test] Enriched logging works') {
                return false;
            }

            $expectedKeys = ['level', 'event', 'message', 'trace_id', 'context', 'timestamp', 'duration_ms', 'memory_mb'];
            foreach ($expectedKeys as $key) {
                if (! array_key_exists($key, $context)) {
                    return false;
                }
            }

            return $context['level'] === 'info'
                && $context['event'] === 'Test:info'
                && $context['message'] === '[Test] Enriched logging works';
        });

    StructuredLogger::from('Test')->info('Enriched logging works');
});

it('logs all levels correctly', function () {
    config()->set('monitor.origin_path_wrapper', 'none');

    $levels = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    foreach ($levels as $level) {
        Log::shouldReceive($level)
            ->once()
            ->withArgs(fn ($message, $context) => $context['level'] === $level);
    }

    $logger = StructuredLogger::from('MultiLevel');

    foreach ($levels as $level) {
        $logger->$level("Level: $level");
    }
});

it('logs via the generic log() method', function () {
    config()->set('monitor.origin_path_wrapper', 'none');

    Log::shouldReceive('info')
        ->once()
        ->withArgs(fn ($message, $context) => $context['level'] === 'info');

    StructuredLogger::from('Generic')->log('info', 'Generic log message');
});

// NEW CRITICAL TESTS:

it('throws exception when prefix is not a string', function () {
    config()->set('monitor.prefix', 123); // Non-string prefix
    config()->set('monitor.origin_path_wrapper', 'none');

    expect(fn () => StructuredLogger::from('Test'))
        ->toThrow(InvalidArgumentException::class, 'Monitor prefix must be a string');
});

it('handles empty replacer arrays', function () {
    config()->set('monitor.prefix', null);
    config()->set('monitor.origin_path_replacers', []);
    config()->set('monitor.origin_separator', ':');
    config()->set('monitor.origin_path_wrapper', 'none');

    $logger = StructuredLogger::from('App\\Test\\Example');

    expect($logger->getOrigin())->toBe('App:Test:Example');
});

it('handles custom context in logging', function () {
    config()->set('monitor.origin_path_wrapper', 'none');

    $customContext = ['user_id' => 123, 'action' => 'test'];

    Log::shouldReceive('info')
        ->once()
        ->withArgs(function ($message, $context) use ($customContext) {
            return $context['context'] === $customContext;
        });

    StructuredLogger::from('Test')->info('Test message', $customContext);
});

it('handles different origin wrapper types', function () {
    $wrapperTests = [
        'none' => 'Test',
        'square' => '[Test]',
        'curly' => '{Test}',
        'round' => '(Test)',
        'angle' => '<Test>',
        'double' => '"Test"',
        'single' => "'Test'",
    ];

    foreach ($wrapperTests as $wrapper => $expected) {
        config()->set('monitor.origin_path_wrapper', $wrapper);
        $logger = StructuredLogger::from('Test');
        expect($logger->getOrigin())->toBe($expected);
    }
});

it('applies multiple replacements in order using strtr', function () {
    config()->set('monitor.prefix', null);
    config()->set('monitor.origin_path_replacers', [
        'App\\' => 'First\\',
        'Test\\' => 'Second\\', // This WILL be applied with strtr()
    ]);
    config()->set('monitor.origin_path_wrapper', 'none');

    $logger = StructuredLogger::from('App\\Test\\Example');

    // With strtr(), both replacements apply: App\ -> First\, then Test\ -> Second\
    expect($logger->getOrigin())->toBe('First:Second:Example');
});

it('processes replacers in order with cascading functionality', function () {
    config()->set('monitor.prefix', null);
    config()->set('monitor.origin_path_replacers', [
        'App\\Services\\' => 'Svc\\',       // More specific match
        'App\\' => 'Application\\',         // Less specific - will still apply to other parts
        'Services\\' => 'Service\\',        // Could apply to different parts
    ]);
    config()->set('monitor.origin_path_wrapper', 'none');

    $logger = StructuredLogger::from('App\\Services\\UserService');

    // With strtr(), the most specific match wins for overlapping patterns
    // App\Services\ -> Svc\ (this prevents App\ from matching the same area)
    expect($logger->getOrigin())->toBe('Svc:UserService');

    // Test another case where multiple non-overlapping replacements apply
    config()->set('monitor.origin_path_replacers', [
        'App\\Http\\Controllers\\Admin\\' => 'Admin\\',
        'App\\Http\\Controllers\\' => 'Web\\',
        'App\\Jobs\\' => 'Job\\',
    ]);

    $logger2 = StructuredLogger::from('App\\Http\\Controllers\\Admin\\UserController');
    // Most specific match wins: App\Http\Controllers\Admin\ -> Admin\
    expect($logger2->getOrigin())->toBe('Admin:UserController');

    $logger3 = StructuredLogger::from('App\\Http\\Controllers\\HomeController');
    // App\Http\Controllers\ -> Web\
    expect($logger3->getOrigin())->toBe('Web:HomeController');

    $logger4 = StructuredLogger::from('App\\Jobs\\ProcessOrder');
    // App\Jobs\ -> Job\
    expect($logger4->getOrigin())->toBe('Job:ProcessOrder');
});

it('uses most specific matching replacer with strtr', function () {
    config()->set('monitor.prefix', null);
    config()->set('monitor.origin_path_replacers', [
        'App\\' => 'First\\',
        'App\\Test\\' => 'Second\\', // This is more specific and WILL be used with strtr()
    ]);
    config()->set('monitor.origin_path_wrapper', 'none');

    $logger = StructuredLogger::from('App\\Test\\Example');

    // strtr() chooses the longest/most specific match: App\Test\ -> Second\
    expect($logger->getOrigin())->toBe('Second:Example');
});
