<?php

declare(strict_types=1);

use Kirschbaum\Monitor\Facades\Monitor;
use Kirschbaum\Monitor\Mcp\MonitorServer;
use Kirschbaum\Monitor\Mcp\Prompts\WrapOperation;
use Kirschbaum\Monitor\Mcp\Resources\Guidelines;
use Kirschbaum\Monitor\Mcp\Resources\RecordSchema;
use Kirschbaum\Monitor\Mcp\ResponseRedactor;
use Kirschbaum\Monitor\Mcp\Tools\Escalations;
use Kirschbaum\Monitor\Mcp\Tools\ExplainPoint;
use Kirschbaum\Monitor\Mcp\Tools\ListPoints;
use Kirschbaum\Monitor\Mcp\Tools\RecentOutcomes;
use Kirschbaum\Monitor\MonitorServiceProvider;
use Kirschbaum\Monitor\Store\StoreOutcomes;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Transport\JsonRpcResponse;
use Workbench\Monitor\ControlPoints\Payments\ChargeCard;
use Workbench\Monitor\Support\StripeClient;

beforeEach(function (): void {
    config()->set('monitor.discovery.paths', [realpath(__DIR__.'/../../workbench/app')]);
    config()->set('monitor.critical_namespaces', []);
    StripeClient::$charge = null;
});

function enableStoreForMcp(): void
{
    config()->set('monitor.records.store.enabled', true);
    (require __DIR__.'/../../database/migrations/create_monitor_outcomes_table.php')->up();
}

describe('server', function (): void {
    it('registers its tools, resources and prompts', function (): void {
        MonitorServer::tools()->assertRegistered([ListPoints::class, ExplainPoint::class, RecentOutcomes::class, Escalations::class]);
        MonitorServer::resources()->assertRegistered([RecordSchema::class, Guidelines::class]);
        MonitorServer::prompts()->assertRegistered([WrapOperation::class]);
    });

    it('is only registered as a local server when enabled', function (): void {
        expect(MonitorServiceProvider::registerMcpServer(app()))->toBeFalse()
            ->and(Mcp::getLocalServer('monitor'))->toBeNull();

        config()->set('monitor.mcp.enabled', true);
        config()->set('monitor.mcp.handle', 'monitor');

        expect(MonitorServiceProvider::registerMcpServer(app()))->toBeTrue()
            ->and(Mcp::getLocalServer('monitor'))->not->toBeNull();
    });
});

describe('tools', function (): void {
    it('lists points with findings and filters by domain', function (): void {
        MonitorServer::tool(ListPoints::class)->assertOk()->assertSee('payment.charge')->assertSee('"findings"')->assertSee('payment.inline');
        MonitorServer::tool(ListPoints::class, ['domain' => 'Courts'])->assertOk()->assertSee('filing.submit')->assertDontSee('payment.refund');
    });

    it('explains a point, with history when the store is on', function (): void {
        MonitorServer::tool(ExplainPoint::class, ['point' => 'payment.charge'])->assertOk()->assertSee('is a control point class')->assertSee('PagePayments is called');
        MonitorServer::tool(ExplainPoint::class, ['point' => 'nope.nope'])->assertHasErrors(['No control point named "nope.nope"']);
        MonitorServer::tool(ExplainPoint::class, ['point' => ''])->assertHasErrors(['Pass the control point name']);

        enableStoreForMcp();
        ChargeCard::run(1, 1);
        resolve(StoreOutcomes::class)->flush();

        MonitorServer::tool(ExplainPoint::class, ['point' => 'payment.charge'])->assertOk()->assertSee('Last 24 hours: 1 succeeded');
    });

    it('refuses outcomes and escalations while the store is off', function (): void {
        MonitorServer::tool(RecentOutcomes::class)->assertHasErrors(['The outcome store is disabled']);
        MonitorServer::tool(Escalations::class)->assertHasErrors(['The outcome store is disabled']);
    });

    it('returns recent outcomes with filters and validates the window', function (): void {
        enableStoreForMcp();
        ChargeCard::run(1, 1);
        StripeClient::$charge = fn () => throw new RuntimeException('gateway down');
        ChargeCard::attempt(2, 2);
        resolve(StoreOutcomes::class)->flush();

        MonitorServer::tool(RecentOutcomes::class)->assertOk()->assertSee('"count":2')->assertSee('gateway down');
        MonitorServer::tool(RecentOutcomes::class, ['status' => 'escalated', 'limit' => 1, 'since' => '1h'])->assertOk()->assertSee('"count":1')->assertSee('"status":"escalated"');
        MonitorServer::tool(RecentOutcomes::class, ['point' => 'nope.nope'])->assertOk()->assertSee('"count":0');
        MonitorServer::tool(RecentOutcomes::class, ['since' => 'yesterday'])->assertHasErrors(['"since" must look like']);
    });

    it('groups escalations and refusals by point', function (): void {
        enableStoreForMcp();
        StripeClient::$charge = fn () => throw new RuntimeException('gateway down');
        ChargeCard::attempt(1, 1);
        ChargeCard::attempt(2, 2);
        Monitor::breaker()->open('stripe', 60);
        ChargeCard::attempt(3, 3);
        resolve(StoreOutcomes::class)->flush();

        MonitorServer::tool(Escalations::class, ['since' => '1h'])->assertOk()
            ->assertSee('"escalated":2')->assertSee('"refused":1')->assertSee('RuntimeException')->assertSee('"last_trace_id"');
        MonitorServer::tool(Escalations::class, ['domain' => 'Nowhere'])->assertOk()->assertSee('"points":[]');
        MonitorServer::tool(Escalations::class, ['since' => 'bad'])->assertHasErrors(['"since" must look like']);
    });
});

describe('resources and prompts', function (): void {
    it('serves the record schema and the guidelines', function (): void {
        MonitorServer::resource(RecordSchema::class)->assertOk()->assertSee('"monitor/1"')->assertSee('point.escalated');
        MonitorServer::resource(Guidelines::class)->assertOk()->assertSee('control point')->assertSee('make:control-point');
    });

    it('builds the wrap_operation prompt from a file', function (): void {
        $file = realpath(__DIR__.'/../../workbench/app/ControlPoints/Filings/Uncontrolled.php');

        MonitorServer::prompt(WrapOperation::class, ['path' => $file, 'point' => 'filing.submit'])->assertOk()
            ->assertSee('Name it "filing.submit"')->assertSee('filed without anyone knowing')->assertSee('## Guidelines');
        MonitorServer::prompt(WrapOperation::class, ['path' => 'missing.php'])->assertOk()->assertSee('Propose a dotted lowercase name');
    });

    it('redacts json structurally and prose line by line, and can be turned off', function (): void {
        $redactor = new ResponseRedactor('observability');

        expect(json_decode($redactor->text('{"trace_id":"0af7651916cd43dd8448eb211c80319c","password":"hunter2","n":1}'), true))
            ->toMatchArray(['trace_id' => '0af7651916cd43dd8448eb211c80319c', 'password' => '[REDACTED]', 'n' => 1])
            ->and($redactor->text("first line\ntoken Xk9vB2mQ7pL4sN8wR1tY6uZ3aC5dF0gHjE2bV here\nlast line"))->toStartWith('first line')->toEndWith('last line')->not->toContain('Xk9vB2mQ7pL4sN8wR1tY6uZ3aC5dF0gHjE2bV')
            ->and($redactor->text('[]'))->toBe('[]')
            ->and((new ResponseRedactor(null))->text('{"password":"hunter2"}'))->toBe('{"password":"hunter2"}');

        $response = JsonRpcResponse::error('1', -1, 'token Xk9vB2mQ7pL4sN8wR1tY6uZ3aC5dF0gHjE2bV leaked');
        expect($redactor->redact($response)->content['error']['message'])->not->toContain('Xk9vB2mQ7pL4sN8wR1tY6uZ3aC5dF0gHjE2bV');
    });

    it('redacts what it returns', function (): void {
        enableStoreForMcp();
        Monitor::control('payment.charge')->with(['password' => 'hunter2', 'invoice' => 5])->run(fn (): int => 1);
        resolve(StoreOutcomes::class)->flush();

        MonitorServer::tool(RecentOutcomes::class)->assertOk()->assertDontSee('hunter2')->assertSee('"invoice":5');
    });
});
