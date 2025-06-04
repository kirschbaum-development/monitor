<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Log\Logger;
use Kirschbaum\Monitor\Formatters\StructuredJsonFormatter;
use Kirschbaum\Monitor\Taps\StructuredLoggingTap;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogHandler;
use Monolog\Logger as MonologLogger;

it('sets StructuredJsonFormatter on AbstractProcessingHandler instances', function () {
    // Create a real Monolog logger
    $monologLogger = new MonologLogger('test');

    // Create handlers - mix of AbstractProcessingHandler and others
    $streamHandler = new StreamHandler('php://memory');
    $syslogHandler = new SyslogHandler('test');
    $nullHandler = new NullHandler;

    // Add handlers to the logger (they are stored in LIFO order)
    $monologLogger->pushHandler($streamHandler);
    $monologLogger->pushHandler($syslogHandler);
    $monologLogger->pushHandler($nullHandler);

    // Create Laravel Logger wrapper
    $logger = new Logger($monologLogger);

    // Apply the tap
    $tap = new StructuredLoggingTap;
    $tap($logger);

    // Verify that AbstractProcessingHandler instances have StructuredJsonFormatter
    $handlers = $logger->getHandlers();

    expect($handlers)->toHaveCount(3);

    // Handlers are in LIFO order: nullHandler[0], syslogHandler[1], streamHandler[2]

    // NullHandler (index 0) does NOT extend AbstractProcessingHandler - should NOT be affected
    expect($handlers[0])->toBeInstanceOf(NullHandler::class);

    // SyslogHandler (index 1) extends AbstractProcessingHandler - should have formatter
    expect($handlers[1])->toBeInstanceOf(SyslogHandler::class)
        ->and($handlers[1]->getFormatter())->toBeInstanceOf(StructuredJsonFormatter::class);

    // StreamHandler (index 2) extends AbstractProcessingHandler - should have formatter
    expect($handlers[2])->toBeInstanceOf(StreamHandler::class)
        ->and($handlers[2]->getFormatter())->toBeInstanceOf(StructuredJsonFormatter::class);
});

it('only affects AbstractProcessingHandler instances', function () {
    $monologLogger = new MonologLogger('test');

    // Add only non-AbstractProcessingHandler
    $nullHandler = new NullHandler;
    $monologLogger->pushHandler($nullHandler);

    $logger = new Logger($monologLogger);

    // Store original handler reference
    $originalHandler = $logger->getHandlers()[0];

    // Apply the tap
    $tap = new StructuredLoggingTap;
    $tap($logger);

    // Handler should be unchanged since it's not AbstractProcessingHandler
    $handlers = $logger->getHandlers();
    expect($handlers[0])->toBe($originalHandler);
});

it('handles empty handler list gracefully', function () {
    $monologLogger = new MonologLogger('test');
    $logger = new Logger($monologLogger);

    // No handlers added
    expect($logger->getHandlers())->toHaveCount(0);

    // Should not throw any errors
    $tap = new StructuredLoggingTap;
    $tap($logger);

    // Still no handlers
    expect($logger->getHandlers())->toHaveCount(0);
});

it('can be invoked multiple times safely', function () {
    $monologLogger = new MonologLogger('test');
    $streamHandler = new StreamHandler('php://memory');
    $monologLogger->pushHandler($streamHandler);

    $logger = new Logger($monologLogger);

    $tap = new StructuredLoggingTap;

    // Apply tap multiple times
    $tap($logger);
    $tap($logger);
    $tap($logger);

    // Handler should still have StructuredJsonFormatter
    $handlers = $logger->getHandlers();
    expect($handlers[0]->getFormatter())->toBeInstanceOf(StructuredJsonFormatter::class);

    // Should only have one handler still
    expect($handlers)->toHaveCount(1);
});

it('works with mixed handler types', function () {
    $monologLogger = new MonologLogger('test');

    // Mix of different handler types
    $streamHandler1 = new StreamHandler('php://memory');
    $streamHandler2 = new StreamHandler('php://stderr');
    $nullHandler = new NullHandler;
    $syslogHandler = new SyslogHandler('app');

    // Add in specific order to know the LIFO result
    $monologLogger->pushHandler($streamHandler1);  // Will be index 3
    $monologLogger->pushHandler($nullHandler);     // Will be index 2
    $monologLogger->pushHandler($streamHandler2);  // Will be index 1
    $monologLogger->pushHandler($syslogHandler);   // Will be index 0

    $logger = new Logger($monologLogger);

    $tap = new StructuredLoggingTap;
    $tap($logger);

    $handlers = $logger->getHandlers();

    // SyslogHandler (index 0) - AbstractProcessingHandler - should have formatter
    expect($handlers[0])->toBeInstanceOf(SyslogHandler::class)
        ->and($handlers[0]->getFormatter())->toBeInstanceOf(StructuredJsonFormatter::class);

    // StreamHandler (index 1) - AbstractProcessingHandler - should have formatter
    expect($handlers[1])->toBeInstanceOf(StreamHandler::class)
        ->and($handlers[1]->getFormatter())->toBeInstanceOf(StructuredJsonFormatter::class);

    // NullHandler (index 2) - should be unchanged (no getFormatter method)
    expect($handlers[2])->toBeInstanceOf(NullHandler::class);

    // StreamHandler (index 3) - AbstractProcessingHandler - should have formatter
    expect($handlers[3])->toBeInstanceOf(StreamHandler::class)
        ->and($handlers[3]->getFormatter())->toBeInstanceOf(StructuredJsonFormatter::class);
});

it('creates new StructuredJsonFormatter instance for each handler', function () {
    $monologLogger = new MonologLogger('test');

    $streamHandler1 = new StreamHandler('php://memory');
    $streamHandler2 = new StreamHandler('php://stderr');

    $monologLogger->pushHandler($streamHandler1);
    $monologLogger->pushHandler($streamHandler2);

    $logger = new Logger($monologLogger);

    $tap = new StructuredLoggingTap;
    $tap($logger);

    $handlers = $logger->getHandlers();

    $formatter1 = $handlers[0]->getFormatter();
    $formatter2 = $handlers[1]->getFormatter();

    // Both should be StructuredJsonFormatter but different instances
    expect($formatter1)->toBeInstanceOf(StructuredJsonFormatter::class)
        ->and($formatter2)->toBeInstanceOf(StructuredJsonFormatter::class)
        ->and($formatter1)->not->toBe($formatter2); // Different instances
});

it('implements callable interface correctly', function () {
    $tap = new StructuredLoggingTap;

    // Should be callable
    expect(is_callable($tap))->toBeTrue();

    // Should be invokable
    expect(method_exists($tap, '__invoke'))->toBeTrue();
});

it('validates input parameter type', function () {
    $tap = new StructuredLoggingTap;

    // Create a real logger to ensure proper type
    $monologLogger = new MonologLogger('test');
    $logger = new Logger($monologLogger);

    // Should accept Logger instance without error - just test it doesn't throw
    $tap($logger);

    // If we get here, no exception was thrown
    expect(true)->toBeTrue();
});
