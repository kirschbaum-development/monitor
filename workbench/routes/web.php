<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use Kirschbaum\Monitor\Facades\Monitor;
use Kirschbaum\Monitor\Http\Middleware\StartTrace;
use Workbench\Monitor\ControlPoints\Payments\ChargeCard;
use Workbench\Monitor\Support\CardDeclined;
use Workbench\Monitor\Support\StripeClient;

/*
 * A payment through a control point, one scenario per URL. Watch the records
 * land in storage/logs/laravel.log while you hit them:
 *
 *   /demo/charge/ok        succeeds
 *   /demo/charge/declined  recovered: the card was declined
 *   /demo/charge/unsettled escalated: the gateway said 200 but the charge is not settled
 *   /demo/charge/down      escalated after retries, and the breaker starts counting
 *   /demo/charge/open      refused: open the breaker first with /demo/breaker/open
 */
Route::middleware(StartTrace::class)->group(function (): void {
    Route::get('/', fn (): JsonResponse => response()->json([
        'scenarios' => ['/demo/charge/ok', '/demo/charge/declined', '/demo/charge/unsettled', '/demo/charge/down', '/demo/breaker/open', '/demo/breaker/close'],
    ]));

    Route::get('/demo/charge/{scenario}', function (string $scenario): JsonResponse {
        StripeClient::$charge = match ($scenario) {
            'declined' => fn () => throw new CardDeclined('insufficient_funds'),
            'unsettled' => fn (int $amount): array => ['amount' => $amount, 'settled' => false],
            'down' => fn () => throw new RuntimeException('gateway unreachable'),
            default => null,
        };

        $outcome = ChargeCard::attempt(48211, 12900);

        return response()->json(['scenario' => $scenario] + $outcome->toArray() + ['value' => $outcome->value]);
    });

    Route::get('/demo/breaker/open', fn (): JsonResponse => response()->json(Monitor::breaker()->open('stripe', 60)->toArray()));
    Route::get('/demo/breaker/close', fn (): JsonResponse => response()->json(Monitor::breaker()->close('stripe')->toArray()));
});
