<?php

declare(strict_types=1);

namespace Tests\Unit;

use Kirschbaum\Monitor\Enums\OriginWrapper;

it('wraps origin with square brackets', function () {
    $wrapper = OriginWrapper::SquareBrackets;
    expect($wrapper->wrap('TestOrigin'))->toBe('[TestOrigin]');
});

it('wraps origin with curly braces', function () {
    $wrapper = OriginWrapper::CurlyBraces;
    expect($wrapper->wrap('TestOrigin'))->toBe('{TestOrigin}');
});

it('wraps origin with parentheses', function () {
    $wrapper = OriginWrapper::Parentheses;
    expect($wrapper->wrap('TestOrigin'))->toBe('(TestOrigin)');
});

it('wraps origin with angle brackets', function () {
    $wrapper = OriginWrapper::AngleBrackets;
    expect($wrapper->wrap('TestOrigin'))->toBe('<TestOrigin>');
});

it('wraps origin with double quotes', function () {
    $wrapper = OriginWrapper::DoubleQuotes;
    expect($wrapper->wrap('TestOrigin'))->toBe('"TestOrigin"');
});

it('wraps origin with single quotes', function () {
    $wrapper = OriginWrapper::SingleQuotes;
    expect($wrapper->wrap('TestOrigin'))->toBe("'TestOrigin'");
});

it('wraps origin with asterisks', function () {
    $wrapper = OriginWrapper::Asterisks;
    expect($wrapper->wrap('TestOrigin'))->toBe('*TestOrigin*');
});

it('does not wrap origin when set to none', function () {
    $wrapper = OriginWrapper::None;
    expect($wrapper->wrap('TestOrigin'))->toBe('TestOrigin');
});

it('handles empty strings correctly for all wrapper types', function () {
    expect(OriginWrapper::None->wrap(''))->toBe('');
    expect(OriginWrapper::SquareBrackets->wrap(''))->toBe('[]');
    expect(OriginWrapper::CurlyBraces->wrap(''))->toBe('{}');
    expect(OriginWrapper::Parentheses->wrap(''))->toBe('()');
    expect(OriginWrapper::AngleBrackets->wrap(''))->toBe('<>');
    expect(OriginWrapper::DoubleQuotes->wrap(''))->toBe('""');
    expect(OriginWrapper::SingleQuotes->wrap(''))->toBe("''");
    expect(OriginWrapper::Asterisks->wrap(''))->toBe('**');
});

it('creates OriginWrapper from config value square', function () {
    $wrapper = OriginWrapper::fromConfig('square');
    expect($wrapper)->toBe(OriginWrapper::SquareBrackets);
});

it('creates OriginWrapper from config value curly', function () {
    $wrapper = OriginWrapper::fromConfig('curly');
    expect($wrapper)->toBe(OriginWrapper::CurlyBraces);
});

it('creates OriginWrapper from config value round', function () {
    $wrapper = OriginWrapper::fromConfig('round');
    expect($wrapper)->toBe(OriginWrapper::Parentheses);
});

it('creates OriginWrapper from config value angle', function () {
    $wrapper = OriginWrapper::fromConfig('angle');
    expect($wrapper)->toBe(OriginWrapper::AngleBrackets);
});

it('creates OriginWrapper from config value double', function () {
    $wrapper = OriginWrapper::fromConfig('double');
    expect($wrapper)->toBe(OriginWrapper::DoubleQuotes);
});

it('creates OriginWrapper from config value single', function () {
    $wrapper = OriginWrapper::fromConfig('single');
    expect($wrapper)->toBe(OriginWrapper::SingleQuotes);
});

it('creates OriginWrapper from config value asterisks', function () {
    $wrapper = OriginWrapper::fromConfig('asterisks');
    expect($wrapper)->toBe(OriginWrapper::Asterisks);
});

it('defaults to None for unknown config values', function () {
    $unknownValues = ['unknown', 'invalid', 'random', ''];

    foreach ($unknownValues as $value) {
        $wrapper = OriginWrapper::fromConfig($value);
        expect($wrapper)->toBe(OriginWrapper::None);
    }
});

it('defaults to None for null config value', function () {
    $wrapper = OriginWrapper::fromConfig(null);
    expect($wrapper)->toBe(OriginWrapper::None);
});

it('handles special characters in origin correctly', function () {
    $specialOrigin = 'Test::Class\\Namespace@method';

    expect(OriginWrapper::None->wrap($specialOrigin))->toBe('Test::Class\\Namespace@method');
    expect(OriginWrapper::SquareBrackets->wrap($specialOrigin))->toBe('[Test::Class\\Namespace@method]');
    expect(OriginWrapper::CurlyBraces->wrap($specialOrigin))->toBe('{Test::Class\\Namespace@method}');
    expect(OriginWrapper::Parentheses->wrap($specialOrigin))->toBe('(Test::Class\\Namespace@method)');
    expect(OriginWrapper::AngleBrackets->wrap($specialOrigin))->toBe('<Test::Class\\Namespace@method>');
    expect(OriginWrapper::DoubleQuotes->wrap($specialOrigin))->toBe('"Test::Class\\Namespace@method"');
    expect(OriginWrapper::SingleQuotes->wrap($specialOrigin))->toBe("'Test::Class\\Namespace@method'");
    expect(OriginWrapper::Asterisks->wrap($specialOrigin))->toBe('*Test::Class\\Namespace@method*');
});

it('maintains wrapper consistency between fromConfig and wrap', function () {
    $configMappings = [
        'square' => OriginWrapper::SquareBrackets,
        'curly' => OriginWrapper::CurlyBraces,
        'round' => OriginWrapper::Parentheses,
        'angle' => OriginWrapper::AngleBrackets,
        'double' => OriginWrapper::DoubleQuotes,
        'single' => OriginWrapper::SingleQuotes,
        'asterisks' => OriginWrapper::Asterisks,
    ];

    foreach ($configMappings as $configValue => $expectedWrapper) {
        $wrapper = OriginWrapper::fromConfig($configValue);
        expect($wrapper)->toBe($expectedWrapper);

        // Test that wrapping works as expected
        $result = $wrapper->wrap('Test');
        expect($result)->not->toBe('Test'); // Should be wrapped (except for None)
    }
});

it('has correct string values for all enum cases', function () {
    expect(OriginWrapper::None->value)->toBe('none');
    expect(OriginWrapper::SquareBrackets->value)->toBe('square');
    expect(OriginWrapper::CurlyBraces->value)->toBe('curly');
    expect(OriginWrapper::Parentheses->value)->toBe('round');
    expect(OriginWrapper::AngleBrackets->value)->toBe('angle');
    expect(OriginWrapper::DoubleQuotes->value)->toBe('double');
    expect(OriginWrapper::SingleQuotes->value)->toBe('single');
    expect(OriginWrapper::Asterisks->value)->toBe('asterisks');
});
