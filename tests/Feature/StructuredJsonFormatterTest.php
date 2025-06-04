<?php

declare(strict_types=1);

namespace Tests\Feature;

use DateTimeImmutable;
use Kirschbaum\Monitor\Formatters\StructuredJsonFormatter;
use Monolog\Level;
use Monolog\LogRecord;

it('formats log record with all required fields', function () {
    $formatter = new StructuredJsonFormatter;

    $record = new LogRecord(
        datetime: new DateTimeImmutable('2024-01-15 10:30:45'),
        channel: 'test-channel',
        level: Level::Info,
        message: 'Test log message',
        context: ['user_id' => 123, 'action' => 'login'],
        extra: ['event' => 'user.authenticated']
    );

    $result = $formatter->format($record);
    $decoded = json_decode(trim($result), true);

    expect($decoded)->toBeArray()
        ->and($decoded['timestamp'])->toBe('2024-01-15T10:30:45+00:00')
        ->and($decoded['level'])->toBe('INFO')
        ->and($decoded['message'])->toBe('Test log message')
        ->and($decoded['context'])->toBe(['user_id' => 123, 'action' => 'login'])
        ->and($decoded['channel'])->toBe('test-channel')
        ->and($decoded['event'])->toBe('user.authenticated')
        ->and($decoded['trace_id'])->toBeNull()
        ->and($decoded['duration_ms'])->toBeNull()
        ->and($decoded['memory_mb'])->toBeNull();
});

it('formats log record with trace_id from context', function () {
    $formatter = new StructuredJsonFormatter;

    $record = new LogRecord(
        datetime: new DateTimeImmutable('2024-01-15 10:30:45'),
        channel: 'monitor',
        level: Level::Debug,
        message: 'Debug message',
        context: ['trace_id' => 'abc123def456', 'operation' => 'database_query'],
        extra: []
    );

    $result = $formatter->format($record);
    $decoded = json_decode(trim($result), true);

    expect($decoded['trace_id'])->toBe('abc123def456')
        ->and($decoded['context'])->toBe(['trace_id' => 'abc123def456', 'operation' => 'database_query']);
});

it('formats log record with duration and memory metrics from context', function () {
    $formatter = new StructuredJsonFormatter;

    $record = new LogRecord(
        datetime: new DateTimeImmutable('2024-01-15 10:30:45'),
        channel: 'performance',
        level: Level::Notice,
        message: 'Operation completed',
        context: [
            'duration_ms' => 150.75,
            'memory_mb' => 25.6,
            'operation' => 'heavy_computation',
        ],
        extra: []
    );

    $result = $formatter->format($record);
    $decoded = json_decode(trim($result), true);

    expect($decoded['duration_ms'])->toBe(150.75)
        ->and($decoded['memory_mb'])->toBe(25.6)
        ->and($decoded['context']['operation'])->toBe('heavy_computation');
});

it('handles all log levels correctly', function () {
    $formatter = new StructuredJsonFormatter;

    $testCases = [
        [Level::Debug, 'DEBUG'],
        [Level::Info, 'INFO'],
        [Level::Notice, 'NOTICE'],
        [Level::Warning, 'WARNING'],
        [Level::Error, 'ERROR'],
        [Level::Critical, 'CRITICAL'],
        [Level::Alert, 'ALERT'],
        [Level::Emergency, 'EMERGENCY'],
    ];

    foreach ($testCases as [$level, $expectedName]) {
        $record = new LogRecord(
            datetime: new DateTimeImmutable('2024-01-15 10:30:45'),
            channel: 'test',
            level: $level,
            message: 'Test message',
            context: [],
            extra: []
        );

        $result = $formatter->format($record);
        $decoded = json_decode(trim($result), true);

        expect($decoded['level'])->toBe($expectedName);
    }
});

it('handles empty context and extra arrays', function () {
    $formatter = new StructuredJsonFormatter;

    $record = new LogRecord(
        datetime: new DateTimeImmutable('2024-01-15 10:30:45'),
        channel: 'empty-test',
        level: Level::Info,
        message: 'Message with empty context',
        context: [],
        extra: []
    );

    $result = $formatter->format($record);
    $decoded = json_decode(trim($result), true);

    expect($decoded['context'])->toBe([])
        ->and($decoded['event'])->toBeNull()
        ->and($decoded['trace_id'])->toBeNull()
        ->and($decoded['duration_ms'])->toBeNull()
        ->and($decoded['memory_mb'])->toBeNull();
});

it('prioritizes trace_id from context over null', function () {
    $formatter = new StructuredJsonFormatter;

    $record = new LogRecord(
        datetime: new DateTimeImmutable('2024-01-15 10:30:45'),
        channel: 'test',
        level: Level::Info,
        message: 'Test message',
        context: ['trace_id' => 'user-defined-123'],
        extra: []
    );

    $result = $formatter->format($record);
    $decoded = json_decode(trim($result), true);

    expect($decoded['trace_id'])->toBe('user-defined-123');
});

it('handles special characters in message correctly', function () {
    $formatter = new StructuredJsonFormatter;

    $specialMessage = 'Test with "quotes", \'apostrophes\', and unicode: 🚀 emoji, newlines:\n\nand tabs:\t\t';

    $record = new LogRecord(
        datetime: new DateTimeImmutable('2024-01-15 10:30:45'),
        channel: 'special-chars',
        level: Level::Warning,
        message: $specialMessage,
        context: [],
        extra: []
    );

    $result = $formatter->format($record);
    $decoded = json_decode(trim($result), true);

    expect($decoded['message'])->toBe($specialMessage);
    expect(json_last_error())->toBe(JSON_ERROR_NONE);
});

it('produces valid NDJSON format with newline', function () {
    $formatter = new StructuredJsonFormatter;

    $record = new LogRecord(
        datetime: new DateTimeImmutable('2024-01-15 10:30:45'),
        channel: 'ndjson-test',
        level: Level::Info,
        message: 'NDJSON format test',
        context: [],
        extra: []
    );

    $result = $formatter->format($record);

    // Should end with exactly one newline
    expect($result)->toEndWith("\n")
        ->and(substr_count($result, "\n"))->toBe(1)
        ->and(rtrim($result, "\n"))->not->toContain("\n");

    // Should be valid JSON when newline is removed
    $jsonPart = trim($result);
    expect(json_decode($jsonPart))->not->toBeNull();
});

it('handles complex nested context data', function () {
    $formatter = new StructuredJsonFormatter;

    $complexContext = [
        'user' => [
            'id' => 456,
            'name' => 'John Doe',
            'roles' => ['admin', 'user'],
        ],
        'request' => [
            'method' => 'POST',
            'url' => '/api/users',
            'headers' => ['Content-Type' => 'application/json'],
        ],
        'metadata' => [
            'ip' => '192.168.1.1',
            'user_agent' => 'TestAgent/1.0',
        ],
    ];

    $record = new LogRecord(
        datetime: new DateTimeImmutable('2024-01-15 10:30:45'),
        channel: 'complex-test',
        level: Level::Error,
        message: 'Complex context test',
        context: $complexContext,
        extra: []
    );

    $result = $formatter->format($record);
    $decoded = json_decode(trim($result), true);

    expect($decoded['context'])->toBe($complexContext)
        ->and($decoded['context']['user']['roles'])->toBe(['admin', 'user'])
        ->and($decoded['context']['request']['method'])->toBe('POST');
});

it('maintains correct timestamp format for different timezones', function () {
    $formatter = new StructuredJsonFormatter;

    // Test with specific timezone
    $datetime = DateTimeImmutable::createFromFormat(
        'Y-m-d H:i:s P',
        '2024-01-15 15:30:45 +02:00'
    );

    $record = new LogRecord(
        datetime: $datetime,
        channel: 'timezone-test',
        level: Level::Info,
        message: 'Timezone test',
        context: [],
        extra: []
    );

    $result = $formatter->format($record);
    $decoded = json_decode(trim($result), true);

    // Should preserve timezone in ISO 8601 format
    expect($decoded['timestamp'])->toBe('2024-01-15T15:30:45+02:00');
});

it('handles event field from extra correctly', function () {
    $formatter = new StructuredJsonFormatter;

    $record = new LogRecord(
        datetime: new DateTimeImmutable('2024-01-15 10:30:45'),
        channel: 'event-test',
        level: Level::Info,
        message: 'Event test',
        context: ['action' => 'create'],
        extra: ['event' => 'user.created', 'processor' => 'structured']
    );

    $result = $formatter->format($record);
    $decoded = json_decode(trim($result), true);

    expect($decoded['event'])->toBe('user.created')
        ->and($decoded['context']['action'])->toBe('create');
});

it('produces consistent JSON structure regardless of input', function () {
    $formatter = new StructuredJsonFormatter;

    // Test multiple different records
    $records = [
        new LogRecord(
            datetime: new DateTimeImmutable('2024-01-15 10:30:45'),
            channel: 'consistency-test-1',
            level: Level::Debug,
            message: 'Debug message',
            context: [],
            extra: []
        ),
        new LogRecord(
            datetime: new DateTimeImmutable('2024-01-15 10:30:46'),
            channel: 'consistency-test-2',
            level: Level::Emergency,
            message: 'Emergency message',
            context: ['critical' => true],
            extra: ['event' => 'system.emergency']
        ),
    ];

    $expectedKeys = [
        'timestamp', 'level', 'message', 'trace_id',
        'context', 'channel', 'event', 'duration_ms', 'memory_mb',
    ];

    foreach ($records as $record) {
        $result = $formatter->format($record);
        $decoded = json_decode(trim($result), true);

        // All records should have the same keys
        expect(array_keys($decoded))->toBe($expectedKeys);
    }
});
