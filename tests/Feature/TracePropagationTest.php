<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Kirschbaum\Monitor\Facades\Monitor;
use Kirschbaum\Monitor\Http\Middleware\StartTrace;
use Kirschbaum\Monitor\Trace\PicksUpJobTrace;
use Kirschbaum\Monitor\Trace\Trace;
use TiMacDonald\Log\LogFake;

beforeEach(function (): void {
    resolve(Trace::class)->clear();

    Route::middleware(StartTrace::class)->get('/traced', fn (): array => ['trace' => Monitor::trace()->current()]);
    Route::middleware('monitor.breakers:stripe,efiling')->get('/guarded', fn (): string => 'through');
    Route::middleware('monitor.trace')->get('/aliased', fn (): array => ['trace' => Monitor::trace()->current()]);
});

describe('StartTrace middleware', function (): void {
    it('starts a trace and returns it as traceparent and the legacy header', function (): void {
        $response = $this->get('/traced')->assertOk();

        $id = $response->json('trace');

        expect($id)->toMatch('/^[0-9a-f]{32}$/')
            ->and($response->headers->get('traceparent'))->toMatch("/^00-{$id}-[0-9a-f]{16}-01$/")
            ->and($response->headers->get('X-Trace-Id'))->toBe($id);
    });

    it('adopts an incoming traceparent', function (): void {
        $response = $this->withHeader('traceparent', '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01')->get('/traced');

        expect($response->json('trace'))->toBe('0af7651916cd43dd8448eb211c80319c');
    });

    it('adopts the legacy header, uuid or hex', function (): void {
        expect($this->withHeader('X-Trace-Id', '9D2B4E8F-3A1C-4D5E-8F2A-1B3C4D5E6F7A')->get('/traced')->json('trace'))->toBe('9d2b4e8f3a1c4d5e8f2a1b3c4d5e6f7a');
        resolve(Trace::class)->clear();
        expect($this->withHeader('X-Trace-Id', 'ffffffffffffffffffffffffffffffff')->get('/traced')->json('trace'))->toBe('ffffffffffffffffffffffffffffffff');
    });

    it('replaces an invalid incoming id and says so once at debug', function (string $header, string $value): void {
        LogFake::bind();

        $response = $this->withHeader($header, $value)->get('/traced');

        expect($response->json('trace'))->toMatch('/^[0-9a-f]{32}$/')->and($response->json('trace'))->not->toBe($value);
        Log::assertLogged(fn ($log): bool => $log->level === 'debug' && str_contains($log->message, 'replaced an invalid incoming'));
    })->with([
        ['traceparent', '00-junk-junk-01'],
        ['traceparent', '<script>alert(1)</script>'],
        ['X-Trace-Id', 'not a trace'],
    ]);

    it('keeps a trace that already started', function (): void {
        resolve(Trace::class)->override('abcdefabcdefabcdefabcdefabcdefab');

        expect($this->withHeader('X-Trace-Id', 'ffffffffffffffffffffffffffffffff')->get('/traced')->json('trace'))->toBe('abcdefabcdefabcdefabcdefabcdefab');
    });

    it('is registered under the monitor.trace alias', function (): void {
        expect($this->get('/aliased')->json('trace'))->toMatch('/^[0-9a-f]{32}$/');
    });

    it('honours configured header names', function (): void {
        config()->set('monitor.trace.legacy_header', 'X-Request-Trace');

        $response = $this->withHeader('X-Request-Trace', 'ffffffffffffffffffffffffffffffff')->get('/traced');

        expect($response->json('trace'))->toBe('ffffffffffffffffffffffffffffffff')
            ->and($response->headers->get('X-Request-Trace'))->toBe('ffffffffffffffffffffffffffffffff');
    });
});

describe('outgoing propagation', function (): void {
    it('adds both headers with Http::traced()', function (): void {
        Http::fake();
        $id = resolve(Trace::class)->override('0af7651916cd43dd8448eb211c80319c');

        Http::traced()->get('https://service-b.test/api');
        Http::baseUrl('https://service-c.test')->traced()->post('/x');

        Http::assertSent(fn (ClientRequest $r): bool => $r->url() === 'https://service-b.test/api'
            && str_starts_with($r->header('traceparent')[0], "00-{$id}-")
            && $r->header('X-Trace-Id')[0] === $id);
        Http::assertSent(fn (ClientRequest $r): bool => $r->url() === 'https://service-c.test/x' && $r->header('X-Trace-Id')[0] === $id);
    });
});

describe('queue pickup', function (): void {
    it('starts a trace for a job that arrived without one', function (): void {
        expect(resolve(Trace::class)->hasStarted())->toBeFalse();

        resolve(PicksUpJobTrace::class)->handle();

        expect(resolve(Trace::class)->hasStarted())->toBeTrue();
    });

    it('is registered on the queue event', function (): void {
        expect(resolve('events')->getListeners(JobProcessing::class))->not->toBeEmpty();
    });
});

describe('CheckBreakers middleware', function (): void {
    it('lets requests through when every circuit is closed', function (): void {
        $this->get('/guarded')->assertOk()->assertSee('through');
    });

    it('refuses with 503 and the real retry-after while a circuit is open', function (): void {
        Monitor::breaker()->open('efiling', 45);

        $this->get('/guarded')
            ->assertStatus(503)
            ->assertHeader('Retry-After', '45')
            ->assertHeader('X-Circuit-Breaker', 'efiling')
            ->assertHeader('X-Circuit-Breaker-Status', 'open');
    });

    it('does not consume the half-open probe', function (): void {
        Monitor::breaker()->open('stripe', 10);
        $this->travel(11)->seconds();

        $this->get('/guarded')->assertOk();

        expect(Monitor::breaker()->attempt('stripe')->probe)->toBeTrue();
    });
});
