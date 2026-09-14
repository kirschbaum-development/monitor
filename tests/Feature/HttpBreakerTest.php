<?php

declare(strict_types=1);

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectionException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Kirschbaum\Monitor\Breaker\BreakerConfig;
use Kirschbaum\Monitor\Facades\Monitor;
use Kirschbaum\Monitor\Http\HttpBreaker;
use Kirschbaum\Monitor\Policies\Once;
use Kirschbaum\Monitor\Risks\BreakerOpen;

describe('Http::breaker()', function (): void {
    it('opens on 5xx responses and refuses without sending', function (): void {
        Http::fake(['stripe.test/*' => Http::response('down', 503)]);

        Http::breaker('stripe', after: 2, within: 60, for: 100)->get('https://stripe.test/charges');
        Http::breaker('stripe', after: 2, within: 60, for: 100)->get('https://stripe.test/charges');

        expect(Monitor::breaker()->isOpen('stripe'))->toBeTrue();
        expect(fn () => Http::breaker('stripe')->get('https://stripe.test/charges'))->toThrow(BreakerOpen::class);
        Http::assertSentCount(2);
    });

    it('counts connection failures and closes on success', function (): void {
        Http::fake(['stripe.test/down' => Http::failedConnection(), 'stripe.test/up' => Http::response('ok', 200)]);

        expect(fn () => Http::baseUrl('https://stripe.test')->breaker('stripe', after: 1)->get('/down'))->toThrow(ConnectionException::class)
            ->and(Monitor::breaker()->isOpen('stripe'))->toBeTrue();

        $this->travel(200)->seconds();

        Http::breaker('stripe', after: 1)->get('https://stripe.test/up');

        expect(Monitor::breaker()->state('stripe')->isClosed())->toBeTrue();
        Http::assertSent(fn (ClientRequest $r): bool => $r->url() === 'https://stripe.test/up');
    });

    it('shares the circuit with control points', function (): void {
        Monitor::breaker()->open('stripe', 60);

        expect(fn () => Http::breaker('stripe')->get('https://stripe.test/x'))->toThrow(BreakerOpen::class)
            ->and(Monitor::control('payment.charge')->breaker('stripe')->attempt(fn (): int => 1)->refused())->toBeTrue();
    });

    it('records a rejection that arrives through the promise', function (): void {
        $middleware = HttpBreaker::middleware(fn () => Monitor::breaker(), 'stripe', new BreakerConfig(1, 60, 100));
        $handler = $middleware(fn (): PromiseInterface => Create::rejectionFor(new RuntimeException('socket closed')));

        expect(fn () => $handler(new Request('GET', 'https://stripe.test/x'), [])->wait())->toThrow(RuntimeException::class, 'socket closed')
            ->and(Monitor::breaker()->isOpen('stripe'))->toBeTrue();

        Monitor::breaker()->close('stripe');
        $handler = $middleware(fn (): PromiseInterface => Create::rejectionFor('not an exception'));

        expect(fn () => $handler(new Request('GET', 'https://stripe.test/x'), [])->wait())->toThrow(RejectionException::class)
            ->and(Monitor::breaker()->isOpen('stripe'))->toBeTrue();
    });

    it('accepts a Once policy object through policy()', function (): void {
        expect(Monitor::control('a.b')->policy(Once::key('k'))->describe()['policies'][0]['type'])->toBe('once');
    });
});
