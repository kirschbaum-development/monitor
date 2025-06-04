<?php

declare(strict_types=1);

namespace Tests\Unit;

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
            'profile' => [
                'name' => 'Test User',
                'settings' => ['theme' => 'dark', 'notifications' => true],
            ],
        ],
        'operation' => [
            'type' => 'data_processing',
            'metrics' => ['records_processed' => 1000, 'errors' => 0],
        ],
        'trace_id' => 'complex-trace-789',
    ];

    $record = new LogRecord(
        datetime: new DateTimeImmutable('2024-01-15 10:30:45'),
        channel: 'complex-test',
        level: Level::Info,
        message: 'Complex data test',
        context: $complexContext,
        extra: []
    );

    $result = $formatter->format($record);
    $decoded = json_decode(trim($result), true);

    expect($decoded['context'])->toBe($complexContext)
        ->and($decoded['trace_id'])->toBe('complex-trace-789')
        ->and($decoded['context']['user']['profile']['name'])->toBe('Test User');
});

it('extracts event from extra field while metrics come from context', function () {
    $formatter = new StructuredJsonFormatter;

    $record = new LogRecord(
        datetime: new DateTimeImmutable('2024-01-15 10:30:45'),
        channel: 'metrics-test',
        level: Level::Info,
        message: 'Metrics test',
        context: [
            'duration_ms' => 250.5,
            'memory_mb' => 32.8,
        ],
        extra: [
            'event' => 'operation.completed',
        ]
    );

    $result = $formatter->format($record);
    $decoded = json_decode(trim($result), true);

    expect($decoded['event'])->toBe('operation.completed')
        ->and($decoded['duration_ms'])->toBe(250.5)
        ->and($decoded['memory_mb'])->toBe(32.8);
});

it('prioritizes context over extra for special fields', function () {
    $formatter = new StructuredJsonFormatter;

    $record = new LogRecord(
        datetime: new DateTimeImmutable('2024-01-15 10:30:45'),
        channel: 'priority-test',
        level: Level::Info,
        message: 'Priority test',
        context: [
            'trace_id' => 'context-trace-123',
            'duration_ms' => 100,
        ],
        extra: [
            'trace_id' => 'extra-trace-456',  // Should be overridden
            'duration_ms' => 200,             // Should be overridden
            'event' => 'test.event',
        ]
    );

    $result = $formatter->format($record);
    $decoded = json_decode(trim($result), true);

    expect($decoded['trace_id'])->toBe('context-trace-123')  // From context
        ->and($decoded['duration_ms'])->toBe(100)             // From context
        ->and($decoded['event'])->toBe('test.event');         // From extra
});

it('handles null and false values correctly', function () {
    $formatter = new StructuredJsonFormatter;

    $record = new LogRecord(
        datetime: new DateTimeImmutable('2024-01-15 10:30:45'),
        channel: 'null-test',
        level: Level::Info,
        message: 'Null test',
        context: [
            'null_value' => null,
            'false_value' => false,
            'zero_value' => 0,
            'empty_string' => '',
        ],
        extra: []
    );

    $result = $formatter->format($record);
    $decoded = json_decode(trim($result), true);

    expect($decoded['context']['null_value'])->toBeNull()
        ->and($decoded['context']['false_value'])->toBeFalse()
        ->and($decoded['context']['zero_value'])->toBe(0)
        ->and($decoded['context']['empty_string'])->toBe('');
});

it('handles very large numbers correctly', function () {
    $formatter = new StructuredJsonFormatter;

    $record = new LogRecord(
        datetime: new DateTimeImmutable('2024-01-15 10:30:45'),
        channel: 'large-numbers',
        level: Level::Info,
        message: 'Large numbers test',
        context: [
            'duration_ms' => 999999.999,
            'memory_mb' => 1024,
            'large_int' => 9223372036854775807, // Max int64
        ],
        extra: []
    );

    $result = $formatter->format($record);
    $decoded = json_decode(trim($result), true);

    expect($decoded['duration_ms'])->toBe(999999.999)
        ->and($decoded['memory_mb'])->toBe(1024)
        ->and($decoded['context']['large_int'])->toBe(9223372036854775807);
});

it('maintains JSON structure integrity', function () {
    $formatter = new StructuredJsonFormatter;

    $record = new LogRecord(
        datetime: new DateTimeImmutable('2024-01-15 10:30:45'),
        channel: 'structure-test',
        level: Level::Info,
        message: 'Structure test',
        context: ['test' => 'value'],
        extra: ['event' => 'test.event']
    );

    $result = $formatter->format($record);

    // Ensure it's valid JSON
    expect(json_decode(trim($result), true))->not->toBeNull()
        ->and(json_last_error())->toBe(JSON_ERROR_NONE);

    // Ensure all required fields are present
    $decoded = json_decode(trim($result), true);
    $requiredFields = ['timestamp', 'level', 'message', 'context', 'channel', 'event', 'trace_id', 'duration_ms', 'memory_mb'];

    foreach ($requiredFields as $field) {
        expect($decoded)->toHaveKey($field);
    }
});
