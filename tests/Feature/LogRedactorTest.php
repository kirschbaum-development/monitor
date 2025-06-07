<?php

declare(strict_types=1);

namespace Tests\Feature;

use Kirschbaum\Monitor\Support\LogRedactor;

it('redacts sensitive keys case-insensitively', function () {
    config()->set('monitor.log_redactor.enabled', true);
    config()->set('monitor.log_redactor.redact_keys', ['password', 'secret', 'api_key']);
    config()->set('monitor.log_redactor.replacement', '[REDACTED]');

    $redactor = new LogRedactor;

    $context = [
        'password' => 'secret123',
        'PASSWORD' => 'secret456',
        'Secret' => 'topsecret',
        'API_KEY' => 'sk-1234567890',
        'normal_field' => 'normal_value',
    ];

    $result = $redactor->redact($context);

    expect($result['password'])->toBe('[REDACTED]')
        ->and($result['PASSWORD'])->toBe('[REDACTED]')
        ->and($result['Secret'])->toBe('[REDACTED]')
        ->and($result['API_KEY'])->toBe('[REDACTED]')
        ->and($result['normal_field'])->toBe('normal_value')
        ->and($result['_redacted'])->toBeTrue();
});

it('redacts strings matching regex patterns', function () {
    config()->set('monitor.log_redactor.enabled', true);
    config()->set('monitor.log_redactor.patterns', [
        'email' => '/[a-zA-Z0-9_.+-]+@[a-zA-Z0-9-]+\.[a-zA-Z0-9-.]+/',
        'credit_card' => '/\b(?:\d[ -]*?){13,16}\b/',
    ]);
    config()->set('monitor.log_redactor.replacement', '[REDACTED]');

    $redactor = new LogRedactor;

    $context = [
        'user_message' => 'Contact me at john@example.com',
        'payment_info' => 'Credit card: 4532-1234-5678-9012',
        'normal_text' => 'This is just normal text',
    ];

    $result = $redactor->redact($context);

    expect($result['user_message'])->toBe('[REDACTED]')
        ->and($result['payment_info'])->toBe('[REDACTED]')
        ->and($result['normal_text'])->toBe('This is just normal text')
        ->and($result['_redacted'])->toBeTrue();
});

it('redacts large strings based on length', function () {
    config()->set('monitor.log_redactor.enabled', true);
    config()->set('monitor.log_redactor.max_value_length', 50);
    config()->set('monitor.log_redactor.replacement', '[REDACTED]');

    $redactor = new LogRedactor;

    $shortString = 'This is a short string';
    $longString = str_repeat('This is a very long string that exceeds the limit. ', 10);

    $context = [
        'short' => $shortString,
        'long' => $longString,
    ];

    $result = $redactor->redact($context);

    expect($result['short'])->toBe($shortString)
        ->and($result['long'])->toContain('[REDACTED]')
        ->and($result['long'])->toContain('(String with')
        ->and($result['_redacted'])->toBeTrue();
});

it('redacts large arrays based on size', function () {
    config()->set('monitor.log_redactor.enabled', true);
    config()->set('monitor.log_redactor.redact_large_objects', true);
    config()->set('monitor.log_redactor.max_object_size', 3);
    config()->set('monitor.log_redactor.replacement', '[REDACTED]');

    $redactor = new LogRedactor;

    $smallArray = ['a' => 1, 'b' => 2];
    $largeArray = ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4, 'e' => 5];

    $context = [
        'small' => $smallArray,
        'large' => $largeArray,
    ];

    $result = $redactor->redact($context);

    expect($result['small'])->toBe($smallArray)
        ->and($result['large'])->toHaveKey('_large_object_redacted')
        ->and($result['large']['_large_object_redacted'])->toContain('[REDACTED]')
        ->and($result['large']['_large_object_redacted'])->toContain('(Array with 5 items)')
        ->and($result['_redacted'])->toBeTrue();
});

it('handles nested arrays and objects recursively', function () {
    config()->set('monitor.log_redactor.enabled', true);
    config()->set('monitor.log_redactor.redact_keys', ['password', 'secret']);
    config()->set('monitor.log_redactor.replacement', '[REDACTED]');

    $redactor = new LogRedactor;

    $context = [
        'user' => [
            'name' => 'John Doe',
            'password' => 'secret123',
            'preferences' => [
                'secret' => 'hidden_value',
                'theme' => 'dark',
            ],
        ],
        'normal_field' => 'normal_value',
    ];

    $result = $redactor->redact($context);

    expect($result['user']['name'])->toBe('John Doe')
        ->and($result['user']['password'])->toBe('[REDACTED]')
        ->and($result['user']['preferences']['secret'])->toBe('[REDACTED]')
        ->and($result['user']['preferences']['theme'])->toBe('dark')
        ->and($result['normal_field'])->toBe('normal_value')
        ->and($result['_redacted'])->toBeTrue();
});

it('can be disabled via configuration', function () {
    config()->set('monitor.log_redactor.enabled', false);

    $redactor = new LogRedactor;

    $context = [
        'password' => 'secret123',
        'email' => 'user@example.com',
    ];

    $result = $redactor->redact($context);

    expect($result)->toBe($context);
});

it('does not add redacted flag when mark_redacted is false', function () {
    config()->set('monitor.log_redactor.enabled', true);
    config()->set('monitor.log_redactor.redact_keys', ['password']);
    config()->set('monitor.log_redactor.replacement', '[REDACTED]');
    config()->set('monitor.log_redactor.mark_redacted', false);

    $redactor = new LogRedactor;

    $context = ['password' => 'secret123'];
    $result = $redactor->redact($context);

    expect($result['password'])->toBe('[REDACTED]')
        ->and($result)->not->toHaveKey('_redacted');
});

it('handles objects by converting them to arrays', function () {
    config()->set('monitor.log_redactor.enabled', true);
    config()->set('monitor.log_redactor.redact_keys', ['password']);
    config()->set('monitor.log_redactor.replacement', '[REDACTED]');

    $redactor = new LogRedactor;

    $object = new \stdClass;
    $object->name = 'John';
    $object->password = 'secret123';

    $context = ['user' => $object];
    $result = $redactor->redact($context);

    expect($result['user']['name'])->toBe('John')
        ->and($result['user']['password'])->toBe('[REDACTED]')
        ->and($result['_redacted'])->toBeTrue();
});

it('handles objects that cannot be JSON encoded', function () {
    config()->set('monitor.log_redactor.enabled', true);
    config()->set('monitor.log_redactor.redact_keys', ['password']);
    config()->set('monitor.log_redactor.replacement', '[REDACTED]');

    $redactor = new LogRedactor;

    // Create an object that cannot be JSON encoded (circular reference)
    $object = new \stdClass;
    $object->name = 'John';
    $object->self = $object; // Circular reference - json_encode will fail

    $context = ['user' => $object];
    $result = $redactor->redact($context);

    // The object should be returned unchanged when json_encode fails
    expect($result['user'])->toBe($object)
        ->and($result)->not->toHaveKey('_redacted');
});

it('handles objects where JSON decode does not return array', function () {
    config()->set('monitor.log_redactor.enabled', true);
    config()->set('monitor.log_redactor.redact_keys', ['password']);
    config()->set('monitor.log_redactor.replacement', '[REDACTED]');

    $redactor = new LogRedactor;

    // Create an object that implements JsonSerializable to return a non-array value
    $mockObject = new class implements \JsonSerializable
    {
        public $name = 'test';

        public function jsonSerialize(): string
        {
            return 'this_will_be_a_string_when_decoded'; // json_decode('"string"', true) returns a string, not array
        }
    };

    $context = ['user' => $mockObject];
    $result = $redactor->redact($context);

    // Should return the original object when json_decode doesn't return an array
    expect($result['user'])->toBe($mockObject)
        ->and($result)->not->toHaveKey('_redacted');
});

it('redacts large objects based on property count', function () {
    config()->set('monitor.log_redactor.enabled', true);
    config()->set('monitor.log_redactor.redact_large_objects', true);
    config()->set('monitor.log_redactor.max_object_size', 2);
    config()->set('monitor.log_redactor.replacement', '[REDACTED]');

    $redactor = new LogRedactor;

    // Create an object with many properties
    $largeObject = new \stdClass;
    $largeObject->prop1 = 'value1';
    $largeObject->prop2 = 'value2';
    $largeObject->prop3 = 'value3';
    $largeObject->prop4 = 'value4';

    $context = ['large_obj' => $largeObject];
    $result = $redactor->redact($context);

    expect($result['large_obj'])->toHaveKey('_large_object_redacted')
        ->and($result['large_obj']['_large_object_redacted'])->toContain('[REDACTED]')
        ->and($result['large_obj']['_large_object_redacted'])->toContain('Object stdClass with 4 properties')
        ->and($result['_redacted'])->toBeTrue();
});

it('handles objects that JSON encode to null or false scenarios', function () {
    config()->set('monitor.log_redactor.enabled', true);
    config()->set('monitor.log_redactor.replacement', '[REDACTED]');

    $redactor = new LogRedactor;

    // Test an object with a resource (which can't be JSON encoded)
    $objectWithResource = new class
    {
        public $resource;

        public $name = 'test';

        public function __construct()
        {
            $this->resource = fopen('php://memory', 'r');
        }

        public function __destruct()
        {
            if (is_resource($this->resource)) {
                fclose($this->resource);
            }
        }
    };

    $context = ['obj_with_resource' => $objectWithResource];
    $result = $redactor->redact($context);

    // Object should be returned unchanged when it can't be JSON encoded
    expect($result['obj_with_resource'])->toBe($objectWithResource);
});

it('integrates with StructuredLogger and redacts context', function () {
    $this->setupLogMocking();

    config()->set('monitor.log_redactor.enabled', true);
    config()->set('monitor.log_redactor.redact_keys', ['password', 'secret']);
    config()->set('monitor.log_redactor.patterns', []); // Disable pattern matching for this test
    config()->set('monitor.log_redactor.replacement', '[REDACTED]');
    config()->set('monitor.origin_path_wrapper', 'none');

    $sensitiveContext = [
        'password' => 'secret123',
        'user_email' => 'user@example.com',
        'secret' => 'hidden',
        'normal_data' => 'visible',
    ];

    \Illuminate\Support\Facades\Log::shouldReceive('info')
        ->once()
        ->withAnyArgs()
        ->andReturnUsing(function ($message, $context) {
            // Test the redaction by examining the context
            $logContext = $context['context'];

            expect($logContext['password'])->toBe('[REDACTED]');
            expect($logContext['secret'])->toBe('[REDACTED]');
            expect($logContext['user_email'])->toBe('user@example.com');
            expect($logContext['normal_data'])->toBe('visible');
            expect($logContext['_redacted'])->toBeTrue();
        });

    \Kirschbaum\Monitor\StructuredLogger::from('Test')->info('Test message', $sensitiveContext);
});
