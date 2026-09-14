<?php

declare(strict_types=1);

use Kirschbaum\Monitor\Support\Domain;
use Kirschbaum\Monitor\Support\PointName;

describe('domain resolution', function (): void {
    it('takes the segment after a null-mapped prefix', function (): void {
        expect(Domain::resolve('App\\ControlPoints\\Payments\\ChargeCard'))->toBe('Payments')
            ->and(Domain::resolve('App\\Services\\Filings\\Submit'))->toBe('Filings')
            ->and(Domain::resolve('App\\Http\\Controllers\\X'))->toBe('Http');
    });

    it('uses a string-mapped prefix as-is and tries prefixes in order', function (): void {
        config()->set('monitor.domains.map', ['App\\Billing\\' => 'Payments', 'App\\' => null]);

        expect(Domain::resolve('App\\Billing\\Invoices\\Send'))->toBe('Payments')
            ->and(Domain::resolve('App\\Other\\Thing'))->toBe('Other');
    });

    it('falls back when nothing matches or the class sits directly under the prefix', function (): void {
        config()->set('monitor.domains.fallback', 'Core');

        expect(Domain::resolve('Vendor\\Package\\Thing'))->toBe('Core')
            ->and(Domain::resolve('App\\Thing'))->toBe('Core');
    });
});

describe('point names', function (): void {
    it('validates against the configured pattern', function (): void {
        expect(PointName::isValid('a.b'))->toBeTrue()->and(PointName::isValid('A.b'))->toBeFalse();

        config()->set('monitor.point_name_pattern', '/^[A-Z]+$/');

        expect(PointName::isValid('ABC'))->toBeTrue()->and(PointName::validate('ABC'))->toBe('ABC');
    });
});
