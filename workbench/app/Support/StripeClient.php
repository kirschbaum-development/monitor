<?php

declare(strict_types=1);

namespace Workbench\Monitor\Support;

/**
 * A stand-in payment gateway the workbench control points call.
 */
final class StripeClient
{
    /** @var (callable(int): array<string, mixed>)|null */
    public static $charge = null;

    /**
     * @return array<string, mixed>
     */
    public function charge(int $amount): array
    {
        $charge = self::$charge;

        if ($charge !== null) {
            return $charge($amount);
        }

        return ['id' => 'ch_'.bin2hex(random_bytes(4)), 'amount' => $amount, 'settled' => true];
    }
}
