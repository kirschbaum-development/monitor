<?php

declare(strict_types=1);

namespace Workbench\Monitor\ControlPoints\Payments;

use Kirschbaum\Monitor\Attributes\Point;
use Kirschbaum\Monitor\Control;
use Kirschbaum\Monitor\ControlPoint;
use Workbench\Monitor\ControlPoints\Escalations\PagePayments;
use Workbench\Monitor\Support\CardDeclined;
use Workbench\Monitor\Support\StripeClient;

#[Point('payment.charge', profile: 'external')]
final class ChargeCard extends ControlPoint
{
    public function __construct(private readonly int $invoiceId, private readonly int $amount) {}

    protected function control(Control $control): void
    {
        $control
            ->breaker('stripe')
            ->attempts(3)
            ->ensure(fn (array $r): bool => $r['settled'] === true, 'charge must be settled')
            ->recover(CardDeclined::class, fn (CardDeclined $e): array => ['declined' => $e->declineCode])
            ->escalate(PagePayments::class);
    }

    public function context(): array
    {
        return ['invoice' => $this->invoiceId, 'amount' => $this->amount];
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(StripeClient $stripe): array
    {
        return $stripe->charge($this->amount);
    }
}
