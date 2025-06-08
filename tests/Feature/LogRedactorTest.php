<?php

declare(strict_types=1);

namespace Tests\Feature;

use Kirschbaum\Monitor\Support\LogRedactor;
use Kirschbaum\Monitor\Support\RedactorConfig;

describe('LogRedactor Configuration Tests', function () {
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
        config()->set('monitor.log_redactor.blocked_keys', ['password']);
        config()->set('monitor.log_redactor.replacement', '[REDACTED]');
        config()->set('monitor.log_redactor.mark_redacted', false);

        $redactor = new LogRedactor;

        $context = ['password' => 'secret123'];
        $result = $redactor->redact($context);

        expect($result['password'])->toBe('[REDACTED]')
            ->and($result)->not->toHaveKey('_redacted');
    });
});

describe('LogRedactor Priority Logic Tests', function () {
    beforeEach(function () {
        config()->set('monitor.log_redactor.enabled', true);
        config()->set('monitor.log_redactor.replacement', '[REDACTED]');
        config()->set('monitor.log_redactor.mark_redacted', true);
    });

    it('prioritizes safe_keys over blocked_keys', function () {
        config()->set('monitor.log_redactor.safe_keys', ['id', 'email']);
        config()->set('monitor.log_redactor.blocked_keys', ['email']);

        $redactor = new LogRedactor;

        $context = [
            'id' => '12345',
            'email' => 'user@example.com',
        ];

        $result = $redactor->redact($context);

        // Safe keys should always show unredacted, even if they're in blocked_keys
        expect($result['id'])->toBe('12345')
            ->and($result['email'])->toBe('user@example.com')
            ->and($result)->not->toHaveKey('_redacted');
    });

    it('prioritizes blocked_keys over regex patterns', function () {
        config()->set('monitor.log_redactor.safe_keys', []);
        config()->set('monitor.log_redactor.blocked_keys', ['user_email']);
        config()->set('monitor.log_redactor.patterns', [
            'email' => '/[a-zA-Z0-9_.+-]+@[a-zA-Z0-9-]+\.[a-zA-Z0-9-.]+/',
        ]);

        $redactor = new LogRedactor;

        $context = [
            'user_email' => 'user@example.com',
            'message' => 'Contact me at admin@example.com',
        ];

        $result = $redactor->redact($context);

        // user_email should be redacted due to blocked_keys
        // message should be redacted due to regex pattern
        expect($result['user_email'])->toBe('[REDACTED]')
            ->and($result['message'])->toBe('[REDACTED]')
            ->and($result['_redacted'])->toBeTrue();
    });

    it('prioritizes regex patterns over shannon entropy', function () {
        config()->set('monitor.log_redactor.safe_keys', []);
        config()->set('monitor.log_redactor.blocked_keys', []);
        config()->set('monitor.log_redactor.patterns', [
            'test_pattern' => '/test_secret_\d+/',
        ]);
        config()->set('monitor.log_redactor.shannon_entropy.enabled', true);
        config()->set('monitor.log_redactor.shannon_entropy.threshold', 1.0); // Very low threshold

        $redactor = new LogRedactor;

        $context = [
            'data' => 'test_secret_123', // Matches regex
            'random' => 'abcdefghijklmnopqrstuvwxyz', // High entropy but no regex match
        ];

        $result = $redactor->redact($context);

        expect($result['data'])->toBe('[REDACTED]') // Redacted by regex
            ->and($result['random'])->toBe('[REDACTED]') // Redacted by entropy
            ->and($result['_redacted'])->toBeTrue();
    });
});

describe('LogRedactor Safe Keys Tests', function () {
    beforeEach(function () {
        config()->set('monitor.log_redactor.enabled', true);
        config()->set('monitor.log_redactor.replacement', '[REDACTED]');
        config()->set('monitor.log_redactor.safe_keys', ['id', 'uuid', 'created_at', 'updated_at']);
        config()->set('monitor.log_redactor.blocked_keys', ['password', 'secret']);
    });

    it('never redacts safe keys', function () {
        $redactor = new LogRedactor;

        $context = [
            'id' => 12345,
            'uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'created_at' => '2023-01-01T00:00:00Z',
            'updated_at' => '2023-01-02T00:00:00Z',
            'password' => 'secret123',
        ];

        $result = $redactor->redact($context);

        expect($result['id'])->toBe(12345)
            ->and($result['uuid'])->toBe('550e8400-e29b-41d4-a716-446655440000')
            ->and($result['created_at'])->toBe('2023-01-01T00:00:00Z')
            ->and($result['updated_at'])->toBe('2023-01-02T00:00:00Z')
            ->and($result['password'])->toBe('[REDACTED]')
            ->and($result['_redacted'])->toBeTrue();
    });

    it('handles safe keys case-insensitively', function () {
        $redactor = new LogRedactor;

        $context = [
            'ID' => 12345,
            'UUID' => '550e8400-e29b-41d4-a716-446655440000',
            'Created_At' => '2023-01-01T00:00:00Z',
            'UPDATED_AT' => '2023-01-02T00:00:00Z',
        ];

        $result = $redactor->redact($context);

        expect($result['ID'])->toBe(12345)
            ->and($result['UUID'])->toBe('550e8400-e29b-41d4-a716-446655440000')
            ->and($result['Created_At'])->toBe('2023-01-01T00:00:00Z')
            ->and($result['UPDATED_AT'])->toBe('2023-01-02T00:00:00Z')
            ->and($result)->not->toHaveKey('_redacted');
    });
});

describe('LogRedactor Blocked Keys Tests', function () {
    beforeEach(function () {
        config()->set('monitor.log_redactor.enabled', true);
        config()->set('monitor.log_redactor.replacement', '[REDACTED]');
        config()->set('monitor.log_redactor.safe_keys', []);
        config()->set('monitor.log_redactor.blocked_keys', ['email', 'ssn', 'ein']);
    });

    it('always redacts blocked keys', function () {
        $redactor = new LogRedactor;

        $context = [
            'email' => 'user@example.com',
            'ssn' => '123-45-6789',
            'ein' => '12-3456789',
            'name' => 'John Doe',
        ];

        $result = $redactor->redact($context);

        expect($result['email'])->toBe('[REDACTED]')
            ->and($result['ssn'])->toBe('[REDACTED]')
            ->and($result['ein'])->toBe('[REDACTED]')
            ->and($result['name'])->toBe('John Doe')
            ->and($result['_redacted'])->toBeTrue();
    });

    it('handles blocked keys case-insensitively', function () {
        $redactor = new LogRedactor;

        $context = [
            'EMAIL' => 'user@example.com',
            'Ssn' => '123-45-6789',
            'EIN' => '12-3456789',
        ];

        $result = $redactor->redact($context);

        expect($result['EMAIL'])->toBe('[REDACTED]')
            ->and($result['Ssn'])->toBe('[REDACTED]')
            ->and($result['EIN'])->toBe('[REDACTED]')
            ->and($result['_redacted'])->toBeTrue();
    });
});

describe('LogRedactor Regex Pattern Tests', function () {
    beforeEach(function () {
        config()->set('monitor.log_redactor.enabled', true);
        config()->set('monitor.log_redactor.replacement', '[REDACTED]');
        config()->set('monitor.log_redactor.safe_keys', []);
        config()->set('monitor.log_redactor.blocked_keys', []);
        config()->set('monitor.log_redactor.redact_keys', []);
    });

    it('redacts strings matching regex patterns', function () {
        config()->set('monitor.log_redactor.patterns', [
            'email' => '/[a-zA-Z0-9_.+-]+@[a-zA-Z0-9-]+\.[a-zA-Z0-9-.]+/',
            'credit_card' => '/\b(?:\d[ -]*?){13,16}\b/',
            'phone' => '/\+?\d[\d -]{8,14}\d/',
        ]);

        $redactor = new LogRedactor;

        $context = [
            'user_message' => 'Contact me at john@example.com',
            'payment_info' => 'Credit card: 4532-1234-5678-9012',
            'contact' => 'Call me at +1-555-123-4567',
            'normal_text' => 'This is just normal text',
        ];

        $result = $redactor->redact($context);

        expect($result['user_message'])->toBe('[REDACTED]')
            ->and($result['payment_info'])->toBe('[REDACTED]')
            ->and($result['contact'])->toBe('[REDACTED]')
            ->and($result['normal_text'])->toBe('This is just normal text')
            ->and($result['_redacted'])->toBeTrue();
    });

    it('handles multiple patterns in same string', function () {
        config()->set('monitor.log_redactor.patterns', [
            'email' => '/[a-zA-Z0-9_.+-]+@[a-zA-Z0-9-]+\.[a-zA-Z0-9-.]+/',
            'phone' => '/\+?\d[\d -]{8,14}\d/',
        ]);

        $redactor = new LogRedactor;

        $context = [
            'contact_info' => 'Email: john@example.com, Phone: +1-555-123-4567',
        ];

        $result = $redactor->redact($context);

        // Should be redacted on first match (email)
        expect($result['contact_info'])->toBe('[REDACTED]')
            ->and($result['_redacted'])->toBeTrue();
    });
});

describe('LogRedactor Shannon Entropy Tests', function () {
    beforeEach(function () {
        config()->set('monitor.log_redactor.enabled', true);
        config()->set('monitor.log_redactor.replacement', '[REDACTED]');
        config()->set('monitor.log_redactor.safe_keys', []);
        config()->set('monitor.log_redactor.blocked_keys', []);
        config()->set('monitor.log_redactor.redact_keys', []);
        config()->set('monitor.log_redactor.patterns', []);
        config()->set('monitor.log_redactor.shannon_entropy.enabled', true);
        config()->set('monitor.log_redactor.shannon_entropy.threshold', 4.0);
        config()->set('monitor.log_redactor.shannon_entropy.min_length', 20);
    });

    it('redacts high entropy strings like API keys', function () {
        $redactor = new LogRedactor;

        $context = [
            'api_key' => 'sk-1234567890abcdef1234567890abcdef12345678', // High entropy, long
            'simple_text' => 'this is a simple text message that is long enough', // Low entropy, long
            'short_random' => 'abc123', // High entropy but too short
        ];

        $result = $redactor->redact($context);

        expect($result['api_key'])->toBe('[REDACTED]')
            ->and($result['simple_text'])->toBe('this is a simple text message that is long enough')
            ->and($result['short_random'])->toBe('abc123')
            ->and($result['_redacted'])->toBeTrue();
    });

    it('can be disabled via configuration', function () {
        config()->set('monitor.log_redactor.shannon_entropy.enabled', false);

        $redactor = new LogRedactor;

        $context = [
            'api_key' => 'sk-1234567890abcdef1234567890abcdef12345678',
        ];

        $result = $redactor->redact($context);

        expect($result['api_key'])->toBe('sk-1234567890abcdef1234567890abcdef12345678')
            ->and($result)->not->toHaveKey('_redacted');
    });

    it('respects minimum length threshold', function () {
        config()->set('monitor.log_redactor.shannon_entropy.min_length', 50);

        $redactor = new LogRedactor;

        $context = [
            'short_key' => 'sk-1234567890abcdef', // High entropy but under min_length
            'long_key' => 'sk-ABCabc123XYZxyz789DEFdef456GHIghi0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ', // High entropy and over min_length (70 chars)
        ];

        $result = $redactor->redact($context);

        expect($result['short_key'])->toBe('sk-1234567890abcdef')
            ->and($result['long_key'])->toBe('[REDACTED]')
            ->and($result['_redacted'])->toBeTrue();
    });

    it('respects entropy threshold', function () {
        config()->set('monitor.log_redactor.shannon_entropy.threshold', 6.0); // Very high threshold

        $redactor = new LogRedactor;

        $context = [
            'medium_entropy' => 'sk-1234567890abcdef1234567890abcdef12345678',
            'very_high_entropy' => 'x9z8y7w6v5u4t3s2r1q0p9o8n7m6l5k4j3h2g1f0e9d8c7b6a5',
        ];

        $result = $redactor->redact($context);

        // With very high threshold, even high-entropy strings might not be redacted
        expect($result['medium_entropy'])->toBe('sk-1234567890abcdef1234567890abcdef12345678');
    });

    it('skips common patterns despite high entropy', function () {
        $redactor = new LogRedactor;

        $context = [
            'url' => 'https://api.example.com/v1/users/123?token=abc123def456ghi789',
            'uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'date' => '2023-12-25T15:30:45.123Z',
            'file_path' => '/usr/local/bin/some-random-executable-name',
            'short_hex' => 'abc123def',
        ];

        $result = $redactor->redact($context);

        // All should be preserved as they match common patterns
        expect($result['url'])->toBe('https://api.example.com/v1/users/123?token=abc123def456ghi789')
            ->and($result['uuid'])->toBe('550e8400-e29b-41d4-a716-446655440000')
            ->and($result['date'])->toBe('2023-12-25T15:30:45.123Z')
            ->and($result['file_path'])->toBe('/usr/local/bin/some-random-executable-name')
            ->and($result['short_hex'])->toBe('abc123def')
            ->and($result)->not->toHaveKey('_redacted');
    });

    it('skips short hexadecimal hashes', function () {
        config()->set('monitor.log_redactor.shannon_entropy.threshold', 3.0); // Low threshold to test hex bypass

        $redactor = new LogRedactor;

        $context = [
            'short_hex_1' => 'abc123',           // 6 chars, hex
            'short_hex_2' => '1234567890abcdef', // 16 chars, hex
            'short_hex_3' => 'deadbeef',         // 8 chars, hex
            'mixed_case_hex' => 'AbC123DeF',     // Mixed case hex
            'not_hex' => 'xyz123',               // Not pure hex
            'long_hex' => '1234567890abcdef1234567890abcdef12', // 34 chars, should potentially be redacted
        ];

        $result = $redactor->redact($context);

        // Short hex strings should be preserved despite high entropy
        expect($result['short_hex_1'])->toBe('abc123')
            ->and($result['short_hex_2'])->toBe('1234567890abcdef')
            ->and($result['short_hex_3'])->toBe('deadbeef')
            ->and($result['mixed_case_hex'])->toBe('AbC123DeF')
            ->and($result['not_hex'])->toBe('xyz123') // Not pure hex, not redacted due to low entropy
            ->and($result['long_hex'])->toBe('[REDACTED]'); // Long hex might be redacted if high entropy
    });

    it('skips whitespace-only strings', function () {
        config()->set('monitor.log_redactor.shannon_entropy.threshold', 0.1); // Very low threshold

        $redactor = new LogRedactor;

        $context = [
            'empty_string' => '',
            'spaces_only' => '   ',
            'tabs_only' => "\t\t\t",
            'mixed_whitespace' => " \t \n \r ",
            'newlines_only' => "\n\n\n",
            'single_space' => ' ',
        ];

        $result = $redactor->redact($context);

        // All whitespace strings should be preserved
        expect($result['empty_string'])->toBe('')
            ->and($result['spaces_only'])->toBe('   ')
            ->and($result['tabs_only'])->toBe("\t\t\t")
            ->and($result['mixed_whitespace'])->toBe(" \t \n \r ")
            ->and($result['newlines_only'])->toBe("\n\n\n")
            ->and($result['single_space'])->toBe(' ')
            ->and($result)->not->toHaveKey('_redacted');
    });

    it('skips IPv4 addresses', function () {
        config()->set('monitor.log_redactor.shannon_entropy.threshold', 3.0); // Low threshold to test IP bypass

        $redactor = new LogRedactor;

        $context = [
            'public_ip' => '192.168.1.1',
            'localhost' => '127.0.0.1',
            'zero_ip' => '0.0.0.0',
            'broadcast' => '255.255.255.255',
            'private_range' => '10.0.0.1',
            'edge_case' => '999.999.999.999', // Invalid IP but matches pattern
            'not_ip' => '192.168.1',         // Incomplete IP
            'ip_with_port' => '192.168.1.1:8080', // IP with port, not pure IP
        ];

        $result = $redactor->redact($context);

        // Valid IPv4 patterns should be preserved
        expect($result['public_ip'])->toBe('192.168.1.1')
            ->and($result['localhost'])->toBe('127.0.0.1')
            ->and($result['zero_ip'])->toBe('0.0.0.0')
            ->and($result['broadcast'])->toBe('255.255.255.255')
            ->and($result['private_range'])->toBe('10.0.0.1')
            ->and($result['edge_case'])->toBe('999.999.999.999') // Pattern matches even if invalid
            ->and($result['not_ip'])->toBe('192.168.1')         // Doesn't match pattern
            ->and($result['ip_with_port'])->toBe('192.168.1.1:8080'); // Doesn't match pattern
    });

    it('skips MAC addresses', function () {
        config()->set('monitor.log_redactor.shannon_entropy.threshold', 3.0); // Low threshold to test MAC bypass

        $redactor = new LogRedactor;

        $context = [
            'mac_lowercase' => '00:1b:44:11:3a:b7',
            'mac_uppercase' => '00:1B:44:11:3A:B7',
            'mac_mixed_case' => '00:1b:44:11:3A:b7',
            'broadcast_mac' => 'ff:ff:ff:ff:ff:ff',
            'zero_mac' => '00:00:00:00:00:00',
            'invalid_mac' => '00:1b:44:11:3a',      // Too short
            'mac_with_dashes' => '00-1b-44-11-3a-b7', // Different separator
            'not_mac' => 'gg:hh:ii:jj:kk:ll',     // Invalid hex characters
        ];

        $result = $redactor->redact($context);

        // Valid MAC address patterns should be preserved
        expect($result['mac_lowercase'])->toBe('00:1b:44:11:3a:b7')
            ->and($result['mac_uppercase'])->toBe('00:1B:44:11:3A:B7')
            ->and($result['mac_mixed_case'])->toBe('00:1b:44:11:3A:b7')
            ->and($result['broadcast_mac'])->toBe('ff:ff:ff:ff:ff:ff')
            ->and($result['zero_mac'])->toBe('00:00:00:00:00:00')
            ->and($result['invalid_mac'])->toBe('00:1b:44:11:3a')      // Doesn't match pattern
            ->and($result['mac_with_dashes'])->toBe('00-1b-44-11-3a-b7') // Different format
            ->and($result['not_mac'])->toBe('gg:hh:ii:jj:kk:ll');     // Invalid characters
    });
});

describe('LogRedactor Common Pattern Detection Tests', function () {
    it('validates isCommonPattern method directly', function () {
        $redactor = new LogRedactor;
        $config = RedactorConfig::fromConfig();
        $reflection = new \ReflectionClass($redactor);
        $method = $reflection->getMethod('isCommonPattern');
        $method->setAccessible(true);

        // Test short hexadecimal hashes (line 405)
        expect($method->invoke($redactor, 'abc123', $config))->toBeTrue()
            ->and($method->invoke($redactor, '1234567890abcdef', $config))->toBeTrue()  // 16 chars
            ->and($method->invoke($redactor, 'deadbeef', $config))->toBeTrue()
            ->and($method->invoke($redactor, 'AbC123DeF', $config))->toBeTrue() // Mixed case
            ->and($method->invoke($redactor, '1234567890abcdef1234567890abcdef12', $config))->toBeFalse(); // 34 chars >= 32

        // Test whitespace-only strings (line 410)
        expect($method->invoke($redactor, '', $config))->toBeTrue()
            ->and($method->invoke($redactor, '   ', $config))->toBeTrue()
            ->and($method->invoke($redactor, "\t\t\t", $config))->toBeTrue()
            ->and($method->invoke($redactor, " \t \n \r ", $config))->toBeTrue()
            ->and($method->invoke($redactor, "\n\n\n", $config))->toBeTrue()
            ->and($method->invoke($redactor, ' ', $config))->toBeTrue();

        // Test IPv4 addresses (line 420)
        expect($method->invoke($redactor, '192.168.1.1', $config))->toBeTrue()
            ->and($method->invoke($redactor, '127.0.0.1', $config))->toBeTrue()
            ->and($method->invoke($redactor, '0.0.0.0', $config))->toBeTrue()
            ->and($method->invoke($redactor, '255.255.255.255', $config))->toBeTrue()
            ->and($method->invoke($redactor, '999.999.999.999', $config))->toBeTrue() // Invalid but matches pattern
            ->and($method->invoke($redactor, '192.168.1', $config))->toBeFalse()     // Incomplete
            ->and($method->invoke($redactor, '192.168.1.1:8080', $config))->toBeFalse(); // With port

        // Test MAC addresses (line 425)
        expect($method->invoke($redactor, '00:1b:44:11:3a:b7', $config))->toBeTrue()
            ->and($method->invoke($redactor, '00:1B:44:11:3A:B7', $config))->toBeTrue()
            ->and($method->invoke($redactor, '00:1b:44:11:3A:b7', $config))->toBeTrue()
            ->and($method->invoke($redactor, 'ff:ff:ff:ff:ff:ff', $config))->toBeTrue()
            ->and($method->invoke($redactor, '00:00:00:00:00:00', $config))->toBeTrue()
            ->and($method->invoke($redactor, '00:1b:44:11:3a', $config))->toBeFalse()      // Too short
            ->and($method->invoke($redactor, '00-1b-44-11-3a-b7', $config))->toBeFalse()   // Different separator
            ->and($method->invoke($redactor, 'gg:hh:ii:jj:kk:ll', $config))->toBeFalse();  // Invalid hex

        // Test other patterns (existing coverage)
        expect($method->invoke($redactor, 'https://example.com', $config))->toBeTrue()
            ->and($method->invoke($redactor, 'http://test.com', $config))->toBeTrue()
            ->and($method->invoke($redactor, '/usr/local/bin/', $config))->toBeTrue()
            ->and($method->invoke($redactor, '2023-12-25', $config))->toBeTrue()
            ->and($method->invoke($redactor, '550e8400-e29b-41d4-a716-446655440000', $config))->toBeTrue()
            ->and($method->invoke($redactor, 'Mozilla/5.0', $config))->toBeTrue();
    });

    it('uses custom entropy exclusion patterns from configuration', function () {
        // Set custom patterns that include a pattern for GitHub commit hashes
        config()->set('monitor.log_redactor.shannon_entropy.exclusion_patterns', [
            '/^https?:\/\//',                    // URLs
            '/^[0-9a-f]{40}$/i',                // Full SHA-1 commit hashes (40 chars)
            '/^custom_prefix_[0-9a-f]{8}$/i',   // Custom pattern
        ]);

        config()->set('monitor.log_redactor.shannon_entropy.enabled', true);
        config()->set('monitor.log_redactor.shannon_entropy.threshold', 3.0); // Low threshold
        config()->set('monitor.log_redactor.shannon_entropy.min_length', 10);

        $redactor = new LogRedactor;

        $context = [
            'commit_hash' => '1234567890abcdef1234567890abcdef12345678', // 40 char SHA-1
            'short_hash' => '1234567890abcdef',                         // 16 chars, should be redacted
            'custom_id' => 'custom_prefix_deadbeef',                    // Matches custom pattern
            'random_data' => 'abcdefghij1234567890',                    // Random high entropy
            'url' => 'https://github.com/user/repo',                   // URL
        ];

        $result = $redactor->redact($context);

        expect($result['commit_hash'])->toBe('1234567890abcdef1234567890abcdef12345678') // Should not be redacted (matches pattern)
            ->and($result['short_hash'])->toBe('[REDACTED]')                              // Should be redacted (high entropy, no matching pattern)
            ->and($result['custom_id'])->toBe('custom_prefix_deadbeef')                   // Should not be redacted (matches custom pattern)
            ->and($result['random_data'])->toBe('[REDACTED]')                             // Should be redacted (high entropy, no matching pattern)
            ->and($result['url'])->toBe('https://github.com/user/repo')                  // Should not be redacted (matches URL pattern)
            ->and($result['_redacted'])->toBeTrue();
    });
});

describe('LogRedactor Shannon Entropy Algorithm Tests', function () {
    it('calculates entropy correctly', function () {
        $redactor = new LogRedactor;
        $reflection = new \ReflectionClass($redactor);
        $method = $reflection->getMethod('calculateShannonEntropy');
        $method->setAccessible(true);

        // Test known entropy values
        $uniform = str_repeat('a', 100); // All same character = 0 entropy
        $binary = str_repeat('ab', 50); // Two characters equally distributed
        $random = 'abcdefghijklmnopqrstuvwxyz1234567890!@#$%^&*()';

        $uniformEntropy = $method->invoke($redactor, $uniform);
        $binaryEntropy = $method->invoke($redactor, $binary);
        $randomEntropy = $method->invoke($redactor, $random);

        expect($uniformEntropy)->toBe(0.0)
            ->and($binaryEntropy)->toBeGreaterThan(0.8)
            ->and($binaryEntropy)->toBeLessThan(1.2)
            ->and($randomEntropy)->toBeGreaterThan($binaryEntropy);
    });

    it('handles edge cases in entropy calculation', function () {
        $redactor = new LogRedactor;
        $reflection = new \ReflectionClass($redactor);
        $method = $reflection->getMethod('calculateShannonEntropy');
        $method->setAccessible(true);

        // Edge cases
        $empty = '';
        $single = 'a';

        expect($method->invoke($redactor, $empty))->toBe(0.0)
            ->and($method->invoke($redactor, $single))->toBe(0.0);
    });
});

describe('LogRedactor Large Object Tests', function () {
    beforeEach(function () {
        config()->set('monitor.log_redactor.enabled', true);
        config()->set('monitor.log_redactor.replacement', '[REDACTED]');
        config()->set('monitor.log_redactor.redact_large_objects', true);
        config()->set('monitor.log_redactor.max_object_size', 3);
    });

    it('redacts large arrays based on size', function () {
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

    it('redacts large objects based on property count', function () {
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
});

describe('LogRedactor String Length Tests', function () {
    beforeEach(function () {
        config()->set('monitor.log_redactor.enabled', true);
        config()->set('monitor.log_redactor.replacement', '[REDACTED]');
        config()->set('monitor.log_redactor.max_value_length', 50);
    });

    it('redacts large strings based on length', function () {
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
});

describe('LogRedactor Nested Structure Tests', function () {
    beforeEach(function () {
        config()->set('monitor.log_redactor.enabled', true);
        config()->set('monitor.log_redactor.replacement', '[REDACTED]');
        config()->set('monitor.log_redactor.safe_keys', ['id', 'name']);
        config()->set('monitor.log_redactor.blocked_keys', ['password', 'secret']);
    });

    it('handles nested arrays and objects recursively', function () {
        $redactor = new LogRedactor;

        $context = [
            'user' => [
                'id' => 123,
                'name' => 'John Doe',
                'password' => 'secret123',
                'preferences' => [
                    'id' => 456,
                    'secret' => 'hidden_value',
                    'theme' => 'dark',
                ],
            ],
            'admin' => [
                'name' => 'Admin User',
                'password' => 'admin_secret',
            ],
        ];

        $result = $redactor->redact($context);

        expect($result['user']['id'])->toBe(123)
            ->and($result['user']['name'])->toBe('John Doe')
            ->and($result['user']['password'])->toBe('[REDACTED]')
            ->and($result['user']['preferences']['id'])->toBe(456)
            ->and($result['user']['preferences']['secret'])->toBe('[REDACTED]')
            ->and($result['user']['preferences']['theme'])->toBe('dark')
            ->and($result['admin']['name'])->toBe('Admin User')
            ->and($result['admin']['password'])->toBe('[REDACTED]')
            ->and($result['_redacted'])->toBeTrue();
    });
});

describe('LogRedactor Object Handling Tests', function () {
    beforeEach(function () {
        config()->set('monitor.log_redactor.enabled', true);
        config()->set('monitor.log_redactor.replacement', '[REDACTED]');
        config()->set('monitor.log_redactor.blocked_keys', ['password']);
    });

    it('handles objects by converting them to arrays', function () {
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

    it('handles objects that JSON encode to null or false scenarios', function () {
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
});

describe('LogRedactor Non-Redactable Object Behavior Tests', function () {
    beforeEach(function () {
        config()->set('monitor.log_redactor.enabled', true);
        config()->set('monitor.log_redactor.replacement', '[REDACTED]');
        config()->set('monitor.log_redactor.blocked_keys', []);
    });

    it('preserves non-redactable objects when behavior is preserve', function () {
        config()->set('monitor.log_redactor.non_redactable_object_behavior', 'preserve');

        $redactor = new LogRedactor;

        // Create an object that cannot be JSON encoded (circular reference)
        $object = new \stdClass;
        $object->name = 'John';
        $object->self = $object; // Circular reference

        $context = ['user' => $object, 'other' => 'value'];
        $result = $redactor->redact($context);

        expect($result['user'])->toBe($object)
            ->and($result['other'])->toBe('value')
            ->and($result)->not->toHaveKey('_redacted');
    });

    it('removes non-redactable objects when behavior is remove', function () {
        config()->set('monitor.log_redactor.non_redactable_object_behavior', 'remove');

        $redactor = new LogRedactor;

        // Create an object that cannot be JSON encoded (circular reference)
        $object = new \stdClass;
        $object->name = 'John';
        $object->self = $object; // Circular reference

        $context = ['user' => $object, 'other' => 'value'];
        $result = $redactor->redact($context);

        expect($result)->not->toHaveKey('user')
            ->and($result['other'])->toBe('value')
            ->and($result['_redacted'])->toBeTrue();
    });

    it('replaces non-redactable objects with empty array when behavior is empty_array', function () {
        config()->set('monitor.log_redactor.non_redactable_object_behavior', 'empty_array');

        $redactor = new LogRedactor;

        // Create an object that cannot be JSON encoded (circular reference)
        $object = new \stdClass;
        $object->name = 'John';
        $object->self = $object; // Circular reference

        $context = ['user' => $object, 'other' => 'value'];
        $result = $redactor->redact($context);

        expect($result['user'])->toBe([])
            ->and($result['other'])->toBe('value')
            ->and($result['_redacted'])->toBeTrue();
    });

    it('replaces non-redactable objects with redaction text when behavior is redact', function () {
        config()->set('monitor.log_redactor.non_redactable_object_behavior', 'redact');

        $redactor = new LogRedactor;

        // Create an object that cannot be JSON encoded (circular reference)
        $object = new \stdClass;
        $object->name = 'John';
        $object->self = $object; // Circular reference

        $context = ['user' => $object, 'other' => 'value'];
        $result = $redactor->redact($context);

        expect($result['user'])->toBeString()
            ->and($result['user'])->toContain('[REDACTED]')
            ->and($result['user'])->toContain('stdClass')
            ->and($result['other'])->toBe('value')
            ->and($result['_redacted'])->toBeTrue();
    });

    it('handles objects with toArray method that throw exceptions but can still be JSON encoded', function () {
        config()->set('monitor.log_redactor.non_redactable_object_behavior', 'redact');

        $redactor = new LogRedactor;

        // Create an object with toArray method that throws exception
        // but can still be JSON encoded (so it will be processed normally)
        $object = new class
        {
            public $name = 'test';

            public function toArray(): array
            {
                throw new \RuntimeException('toArray failed');
            }
        };

        $context = ['user' => $object];
        $result = $redactor->redact($context);

        // Since the object can be JSON encoded, it will be processed normally
        expect($result['user'])->toBeArray()
            ->and($result['user']['name'])->toBe('test')
            ->and($result)->not->toHaveKey('_redacted');
    });

    it('handles objects that truly cannot be processed', function () {
        config()->set('monitor.log_redactor.non_redactable_object_behavior', 'redact');

        $redactor = new LogRedactor;

        // Create an object that has both failing toArray and circular reference
        // This will fail both toArray and JSON encoding
        $object = new class
        {
            public $name = 'test';

            public $self;

            public function __construct()
            {
                $this->self = $this; // Circular reference
            }

            public function toArray(): array
            {
                throw new \RuntimeException('toArray failed');
            }
        };

        $context = ['user' => $object];
        $result = $redactor->redact($context);

        expect($result['user'])->toBeString()
            ->and($result['user'])->toContain('[REDACTED]')
            ->and($result['user'])->toContain('class@anonymous')
            ->and($result['_redacted'])->toBeTrue();
    });

    it('tracks redacted keys when track_redacted_keys is enabled', function () {
        config()->set('monitor.log_redactor.track_redacted_keys', true);
        config()->set('monitor.log_redactor.non_redactable_object_behavior', 'remove');
        config()->set('monitor.log_redactor.blocked_keys', ['password']);

        $redactor = new LogRedactor;

        // Create an object that cannot be JSON encoded (circular reference)
        $object = new \stdClass;
        $object->self = $object;

        $context = [
            'user' => $object, // Will be removed
            'password' => 'secret', // Will be redacted via blocked_keys
            'other' => 'value',
        ];
        $result = $redactor->redact($context);

        expect($result)->not->toHaveKey('user')
            ->and($result['password'])->toBe('[REDACTED]')
            ->and($result['other'])->toBe('value')
            ->and($result['_redacted'])->toBeTrue()
            ->and($result['_redacted_keys'])->toContain('password');
    });

    it('handles objects that cause JSON decode to return non-array with different behaviors', function () {
        config()->set('monitor.log_redactor.non_redactable_object_behavior', 'empty_array');

        $redactor = new LogRedactor;

        // Create an object that implements JsonSerializable to return a non-array value
        $mockObject = new class implements \JsonSerializable
        {
            public function jsonSerialize(): string
            {
                return 'this_will_be_a_string_when_decoded';
            }
        };

        $context = ['user' => $mockObject];
        $result = $redactor->redact($context);

        expect($result['user'])->toBe([])
            ->and($result['_redacted'])->toBeTrue();
    });

    it('logs warnings for non-redactable objects as promised', function () {
        config()->set('monitor.log_redactor.non_redactable_object_behavior', 'preserve');

        \TiMacDonald\Log\LogFake::bind();

        $redactor = new LogRedactor;

        // Test case 1: Circular reference (JSON encode fails)
        $circularObject = new \stdClass;
        $circularObject->self = $circularObject;

        $context1 = ['circular' => $circularObject];
        $result1 = $redactor->redact($context1);

        expect($result1['circular'])->toBe($circularObject);

        // Verify warning was logged for JSON exception (circular reference)
        \Illuminate\Support\Facades\Log::assertLogged(function (\TiMacDonald\Log\LogEntry $log) {
            return $log->level === 'warning'
                && str_contains($log->message, '[LogRedactor] Exception while trying to redact object');
        });

        // Test case 2: Object with JsonSerializable returning non-array
        $nonArrayObject = new class implements \JsonSerializable
        {
            public function jsonSerialize(): string
            {
                return 'not_an_array';
            }
        };

        $context2 = ['non_array' => $nonArrayObject];
        $result2 = $redactor->redact($context2);

        expect($result2['non_array'])->toBe($nonArrayObject);

        // Verify warning was logged for JSON decode not returning array
        \Illuminate\Support\Facades\Log::assertLogged(function (\TiMacDonald\Log\LogEntry $log) {
            return $log->level === 'warning'
                && str_contains($log->message, '[LogRedactor] Unable to redact object - JSON decode did not return array');
        });

        // Test case 3: Object with resource that causes JSON encode to fail
        $resourceObject = new class
        {
            public $resource;

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

        $context3 = ['resource' => $resourceObject];
        $result3 = $redactor->redact($context3);

        expect($result3['resource'])->toBe($resourceObject);

        // Verify warning was logged for resource object (JSON exception)
        \Illuminate\Support\Facades\Log::assertLogged(function (\TiMacDonald\Log\LogEntry $log) {
            return $log->level === 'warning'
                && str_contains($log->message, '[LogRedactor] Exception while trying to redact object');
        });
    });

    it('logs warnings when exceptions occur during object processing', function () {
        config()->set('monitor.log_redactor.non_redactable_object_behavior', 'redact');

        \TiMacDonald\Log\LogFake::bind();

        $redactor = new LogRedactor;

        // Create an object that has both failing toArray and circular reference
        // This will cause an exception during JSON processing
        $object = new class
        {
            public $name = 'test';

            public $self;

            public function __construct()
            {
                $this->self = $this; // Circular reference
            }

            public function toArray(): array
            {
                throw new \RuntimeException('toArray failed');
            }
        };

        $context = ['user' => $object];
        $result = $redactor->redact($context);

        expect($result['user'])->toBeString()
            ->and($result['user'])->toContain('[REDACTED]')
            ->and($result['user'])->toContain('class@anonymous')
            ->and($result['_redacted'])->toBeTrue();

        // Verify warning was logged for the exception
        \Illuminate\Support\Facades\Log::assertLogged(function (\TiMacDonald\Log\LogEntry $log) {
            return $log->level === 'warning'
                && str_contains($log->message, '[LogRedactor] Exception while trying to redact object');
        });
    });

    it('properly redacts objects with working toArray method', function () {
        config()->set('monitor.log_redactor.blocked_keys', ['password', 'secret']);
        config()->set('monitor.log_redactor.safe_keys', ['id']);

        $redactor = new LogRedactor;

        // Create an object with a working toArray method that returns redactable content
        $object = new class
        {
            public function toArray(): array
            {
                return [
                    'id' => 123,
                    'password' => 'secret123',
                    'secret' => 'hidden',
                    'name' => 'John Doe',
                ];
            }
        };

        $context = ['user' => $object];
        $result = $redactor->redact($context);

        // Should redact the array returned by toArray()
        expect($result['user'])->toBeArray()
            ->and($result['user']['id'])->toBe(123) // Safe key
            ->and($result['user']['password'])->toBe('[REDACTED]') // Blocked key
            ->and($result['user']['secret'])->toBe('[REDACTED]') // Blocked key
            ->and($result['user']['name'])->toBe('John Doe') // Normal data
            ->and($result['_redacted'])->toBeTrue();
    });

    it('logs warnings when actual exceptions are thrown during JSON processing', function () {
        config()->set('monitor.log_redactor.non_redactable_object_behavior', 'preserve');

        \TiMacDonald\Log\LogFake::bind();

        $redactor = new LogRedactor;

        // Create an object that will cause a JSON encoding exception
        // Circular references will now throw with JSON_THROW_ON_ERROR
        $object = new \stdClass;
        $object->self = $object; // Circular reference

        $context = ['problematic' => $object];
        $result = $redactor->redact($context);

        // Should handle the exception and return original object (preserve behavior)
        expect($result['problematic'])->toBe($object);

        // Verify warning was logged for the actual exception
        \Illuminate\Support\Facades\Log::assertLogged(function (\TiMacDonald\Log\LogEntry $log) {
            return $log->level === 'warning'
                && str_contains($log->message, '[LogRedactor] Exception while trying to redact object')
                && isset($log->context['context']['reason'])
                && $log->context['context']['reason'] === 'exception_during_processing'
                && isset($log->context['context']['exception_type'])
                && $log->context['context']['exception_type'] === 'JsonException';
        });
    });
});

describe('RedactorConfig DTO Tests', function () {
    it('creates config from Laravel configuration with defaults', function () {
        // Clear all config to test defaults
        config()->set('monitor.log_redactor.safe_keys', []);
        config()->set('monitor.log_redactor.blocked_keys', []);
        config()->set('monitor.log_redactor.patterns', []);
        // Don't set replacement to test default behavior
        config()->set('monitor.log_redactor.max_value_length', null);

        $config = \Kirschbaum\Monitor\Support\RedactorConfig::fromConfig();

        expect($config->safeKeys)->toBe([])
            ->and($config->blockedKeys)->toBe([])
            ->and($config->patterns)->toBe([])
            ->and($config->replacement)->toBe('[REDACTED]')
            ->and($config->maxValueLength)->toBeNull()
            ->and($config->redactLargeObjects)->toBeTrue()
            ->and($config->maxObjectSize)->toBe(100)
            ->and($config->enableShannonEntropy)->toBeTrue()
            ->and($config->entropyThreshold)->toBe(4.8)
            ->and($config->minLength)->toBe(25)
            ->and($config->markRedacted)->toBeTrue()
            ->and($config->trackRedactedKeys)->toBeFalse()
            ->and($config->nonRedactableObjectBehavior)->toBe('preserve');
    });

    it('creates config with custom values and handles invalid patterns', function () {
        config()->set('monitor.log_redactor.safe_keys', ['ID', 'User_ID']);
        config()->set('monitor.log_redactor.blocked_keys', ['PASSWORD', 'Secret']);
        config()->set('monitor.log_redactor.patterns', [
            '/valid-pattern/',
            '(invalid-pattern', // Invalid regex
            '/another-valid-pattern/',
        ]);
        config()->set('monitor.log_redactor.replacement', '[CUSTOM]');
        config()->set('monitor.log_redactor.max_value_length', 100);
        config()->set('monitor.log_redactor.track_redacted_keys', true);
        config()->set('monitor.log_redactor.non_redactable_object_behavior', 'remove');

        $config = \Kirschbaum\Monitor\Support\RedactorConfig::fromConfig();

        expect($config->safeKeys)->toBe(['id', 'user_id']) // Converted to lowercase
            ->and($config->blockedKeys)->toBe(['password', 'secret']) // Converted to lowercase
            ->and($config->patterns)->toBe(['/valid-pattern/', '/another-valid-pattern/']) // Invalid pattern filtered out
            ->and($config->replacement)->toBe('[CUSTOM]')
            ->and($config->maxValueLength)->toBe(100)
            ->and($config->trackRedactedKeys)->toBeTrue()
            ->and($config->nonRedactableObjectBehavior)->toBe('remove');
    });

    it('handles non-integer max_value_length config', function () {
        config()->set('monitor.log_redactor.max_value_length', 'not_an_integer');

        $config = \Kirschbaum\Monitor\Support\RedactorConfig::fromConfig();

        expect($config->maxValueLength)->toBeNull();
    });
});

describe('LogRedactor Integration Tests', function () {
    it('integrates with StructuredLogger and redacts context', function () {
        $this->setupLogMocking();

        config()->set('monitor.log_redactor.enabled', true);
        config()->set('monitor.log_redactor.safe_keys', ['user_id']);
        config()->set('monitor.log_redactor.blocked_keys', ['password', 'secret']);
        config()->set('monitor.log_redactor.patterns', [
            'email' => '/[a-zA-Z0-9_.+-]+@[a-zA-Z0-9-]+\.[a-zA-Z0-9-.]+/',
        ]);
        config()->set('monitor.log_redactor.replacement', '[REDACTED]');
        config()->set('monitor.origin_path_wrapper', 'none');

        $sensitiveContext = [
            'user_id' => 12345,
            'password' => 'secret123',
            'user_email' => 'user@example.com', // Should be redacted by regex
            'secret' => 'hidden',
            'normal_data' => 'visible',
        ];

        \Illuminate\Support\Facades\Log::shouldReceive('info')
            ->once()
            ->withAnyArgs()
            ->andReturnUsing(function ($message, $context) {
                // Test the redaction by examining the context
                $logContext = $context['context'];

                expect($logContext['user_id'])->toBe(12345); // Safe key
                expect($logContext['password'])->toBe('[REDACTED]'); // Blocked key
                expect($logContext['user_email'])->toBe('[REDACTED]'); // Regex pattern
                expect($logContext['secret'])->toBe('[REDACTED]'); // Blocked key
                expect($logContext['normal_data'])->toBe('visible'); // Normal data
                expect($logContext['_redacted'])->toBeTrue();
            });

        \Kirschbaum\Monitor\StructuredLogger::from('Test')->info('Test message', $sensitiveContext);
    });
});

describe('LogRedactor Real-world Scenario Tests', function () {
    beforeEach(function () {
        config()->set('monitor.log_redactor.enabled', true);
        config()->set('monitor.log_redactor.replacement', '[REDACTED]');
        config()->set('monitor.log_redactor.safe_keys', ['id', 'user_id', 'created_at', 'updated_at']);
        config()->set('monitor.log_redactor.blocked_keys', ['email', 'ssn', 'password', 'api_key']);
        config()->set('monitor.log_redactor.patterns', [
            'email' => '/[a-zA-Z0-9_.+-]+@[a-zA-Z0-9-]+\.[a-zA-Z0-9-.]+/',
        ]);
        config()->set('monitor.log_redactor.shannon_entropy.enabled', true);
        config()->set('monitor.log_redactor.shannon_entropy.threshold', 4.0);
        config()->set('monitor.log_redactor.shannon_entropy.min_length', 20);
    });

    it('handles realistic user registration context', function () {
        $redactor = new LogRedactor;

        $context = [
            'user_id' => 12345,
            'email' => 'john.doe@example.com',
            'password' => 'MySecretPassword123!',
            'ssn' => '123-45-6789',
            'created_at' => '2023-12-25T15:30:45Z',
            'ip_address' => '192.168.1.100',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            'registration_source' => 'web',
        ];

        $result = $redactor->redact($context);

        expect($result['user_id'])->toBe(12345) // Safe key
            ->and($result['email'])->toBe('[REDACTED]') // Blocked key
            ->and($result['password'])->toBe('[REDACTED]') // Blocked key
            ->and($result['ssn'])->toBe('[REDACTED]') // Blocked key
            ->and($result['created_at'])->toBe('2023-12-25T15:30:45Z') // Safe key
            ->and($result['ip_address'])->toBe('192.168.1.100') // Not redacted
            ->and($result['user_agent'])->toBe('Mozilla/5.0 (Windows NT 10.0; Win64; x64)') // Not redacted
            ->and($result['registration_source'])->toBe('web') // Not redacted
            ->and($result['_redacted'])->toBeTrue();
    });

    it('handles API request context with tokens', function () {
        $redactor = new LogRedactor;

        $context = [
            'request_id' => 'req_123456',
            'user_id' => 78901,
            'api_key' => 'sk-1234567890abcdef1234567890abcdef12345678',
            'stripe_token' => 'tok_1ABCDEfghijklmnop2QRSTUv', // High entropy
            'jwt_payload' => 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIiwibmFtZSI6IkpvaG4gRG9lIiwiaWF0IjoxNTE2MjM5MDIyfQ.SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c',
            'endpoint' => '/api/v1/users',
            'method' => 'POST',
            'created_at' => '2023-12-25T16:00:00Z',
        ];

        $result = $redactor->redact($context);

        expect($result['request_id'])->toBe('req_123456') // Not redacted
            ->and($result['user_id'])->toBe(78901) // Safe key
            ->and($result['api_key'])->toBe('[REDACTED]') // Blocked key
            ->and($result['stripe_token'])->toBe('[REDACTED]') // High entropy
            ->and($result['jwt_payload'])->toBe('[REDACTED]') // High entropy
            ->and($result['endpoint'])->toBe('/api/v1/users') // Not redacted
            ->and($result['method'])->toBe('POST') // Not redacted
            ->and($result['created_at'])->toBe('2023-12-25T16:00:00Z') // Safe key
            ->and($result['_redacted'])->toBeTrue();
    });
});
