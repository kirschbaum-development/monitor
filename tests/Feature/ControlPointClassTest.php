<?php

declare(strict_types=1);

use Kirschbaum\Monitor\Attributes\Point;
use Kirschbaum\Monitor\Control;
use Kirschbaum\Monitor\ControlPoint;
use Kirschbaum\Monitor\Exceptions\InvalidControlPoint;
use Kirschbaum\Monitor\Facades\Monitor;
use Kirschbaum\Monitor\Outcome;
use Kirschbaum\Monitor\Risks\EnsureFailed;
use Kirschbaum\Monitor\Status;
use Workbench\Monitor\ControlPoints\Escalations\PagePayments;
use Workbench\Monitor\ControlPoints\Filings\SubmitFiling;
use Workbench\Monitor\ControlPoints\Payments\ChargeCard;
use Workbench\Monitor\ControlPoints\Payments\NeedsConstructor;
use Workbench\Monitor\ControlPoints\Payments\RefundCard;
use Workbench\Monitor\Support\CardDeclined;
use Workbench\Monitor\Support\StripeClient;

final class NoAttribute extends ControlPoint
{
    public function handle(): int
    {
        return 1;
    }
}

#[Point('no.handle')]
final class NoHandle extends ControlPoint {}

beforeEach(function (): void {
    StripeClient::$charge = null;
    PagePayments::$paged = [];
    config()->set('monitor.domains.map', ['Workbench\\Monitor\\ControlPoints\\' => null]);
});

describe('class form', function (): void {
    it('runs with constructor arguments and container-injected handle()', function (): void {
        $result = ChargeCard::run(48211, 12900);

        expect($result)->toMatchArray(['amount' => 12900, 'settled' => true]);
    });

    it('returns an Outcome from attempt() with the attribute\'s name, profile and the instance context', function (): void {
        $outcome = ChargeCard::attempt(48211, 12900);

        expect($outcome)->toBeInstanceOf(Outcome::class)
            ->and($outcome->point)->toBe('payment.charge')
            ->and($outcome->profile)->toBe('external')
            ->and($outcome->origin)->toBe(ChargeCard::class)
            ->and($outcome->domain)->toBe('Payments')
            ->and($outcome->context)->toBe(['invoice' => 48211, 'amount' => 12900])
            ->and($outcome->status)->toBe(Status::Succeeded);
    });

    it('applies its risks, limits and escalation', function (): void {
        StripeClient::$charge = fn () => throw new CardDeclined('insufficient_funds');
        expect(ChargeCard::run(1, 100))->toBe(['declined' => 'insufficient_funds']);

        StripeClient::$charge = fn (int $amount): array => ['amount' => $amount, 'settled' => false];
        $outcome = ChargeCard::attempt(1, 100);
        expect($outcome->status)->toBe(Status::Escalated)
            ->and($outcome->exception)->toBeInstanceOf(EnsureFailed::class)
            ->and(PagePayments::$paged)->toHaveCount(1)
            ->and(PagePayments::$paged[0]->point)->toBe('payment.charge');
    });

    it('can be executed as an instance', function (): void {
        expect((new ChargeCard(2, 300))->execute()->value['amount'])->toBe(300);
    });

    it('honours an explicit domain on the attribute', function (): void {
        expect(SubmitFiling::attempt()->domain)->toBe('Courts');
    });

    it('is recorded by the fake like any other point', function (): void {
        Monitor::fake()->failing('payment.refund', new CardDeclined);

        RefundCard::run();

        Monitor::assertRecovered('payment.refund', from: CardDeclined::class);
    });

    it('describes itself statically without a constructor', function (): void {
        $described = ChargeCard::describe();

        expect($described)->toMatchArray(['point' => 'payment.charge', 'origin' => ChargeCard::class, 'profile' => 'external', 'form' => 'class', 'notes' => [], 'escalation' => PagePayments::class, 'risks' => [CardDeclined::class]])
            ->and(array_column($described['policies'], 'type'))->toBe(['breaker', 'retry'])
            ->and($described['policies'][0]['name'])->toBe('stripe');
    });

    it('notes when control() cannot be read without the constructor', function (): void {
        $described = NeedsConstructor::describe();

        expect($described['notes'][0])->toStartWith('control() could not be read statically')
            ->and(NeedsConstructor::run('stripe'))->toBeTrue();
    });

    it('requires the attribute and a handle method', function (): void {
        expect(fn (): mixed => NoAttribute::run())->toThrow(InvalidControlPoint::class, 'must carry a #[Point');
        expect(fn (): mixed => NoHandle::run())->toThrow(InvalidControlPoint::class, 'must define a public handle()');
    });

    it('exposes the Control it builds', function (): void {
        $control = (new ChargeCard(1, 1))->toControl();

        expect($control)->toBeInstanceOf(Control::class)->and($control->name())->toBe('payment.charge')->and($control->context())->toBe(['invoice' => 1, 'amount' => 1]);
    });
});
