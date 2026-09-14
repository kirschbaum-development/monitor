<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Kirschbaum\Monitor\Inventory\AstScanner;
use Kirschbaum\Monitor\Inventory\Discovery;
use Kirschbaum\Monitor\Inventory\Explainer;
use Kirschbaum\Monitor\Inventory\Finding;
use Kirschbaum\Monitor\Inventory\PointDescription;
use Kirschbaum\Monitor\Inventory\Reports\JsonReport;
use Kirschbaum\Monitor\Inventory\Reports\SarifReport;
use Kirschbaum\Monitor\Inventory\Rules\Rules;
use Kirschbaum\Monitor\Store\OutcomeStore;
use Kirschbaum\Monitor\Store\StoreOutcomes;
use Workbench\Monitor\ControlPoints\Escalations\PagePayments;
use Workbench\Monitor\ControlPoints\Filings\Unnamed;
use Workbench\Monitor\ControlPoints\Payments\ChargeCard;
use Workbench\Monitor\ControlPoints\Payments\InlineCharger;
use Workbench\Monitor\Support\CardDeclined;

function workbenchPath(): string
{
    return realpath(__DIR__.'/../../workbench/app') ?: '';
}

beforeEach(function (): void {
    config()->set('monitor.discovery.paths', [workbenchPath()]);
    config()->set('monitor.critical_namespaces', ['Workbench\\Monitor\\ControlPoints']);
});

describe('discovery', function (): void {
    it('finds class-form points fully and inline points by name', function (): void {
        $inventory = resolve(Discovery::class)->build();

        $names = array_map(fn (PointDescription $p): string => $p->name, $inventory->points);
        sort($names);

        expect($names)->toBe(['(dynamic)', 'BadName', Unnamed::class, 'filing.submit', 'payment.capture', 'payment.charge', 'payment.charge', 'payment.direct', 'payment.inline', 'payment.refund']);

        $charge = $inventory->find('payment.charge');
        $inline = $inventory->find('payment.inline');

        expect($charge->isClassForm())->toBeTrue()
            ->and($charge->origin)->toBe(ChargeCard::class)
            ->and($charge->file)->toEndWith('ChargeCard.php')
            ->and($charge->line)->toBeInt()
            ->and($charge->risks)->toBe([CardDeclined::class])
            ->and($charge->escalation)->toBe(PagePayments::class)
            ->and(array_column($charge->policies, 'type'))->toBe(['breaker', 'retry'])
            ->and($charge->limits[1])->toBe(['type' => 'attempts', 'max' => 3])
            ->and($inline->isClassForm())->toBeFalse()
            ->and($inline->origin)->toBe(InlineCharger::class)
            ->and($inline->line)->toBeInt()
            ->and($inline->policies)->toBe([])
            ->and($inline->notes[0])->toContain('inventoried by name');
    });

    it('raises one finding per rule on the deliberately wrong fixtures', function (): void {
        $inventory = resolve(Discovery::class)->build();

        $byRule = [];
        foreach ($inventory->findings() as $finding) {
            $byRule[$finding->rule][] = $finding;
        }

        expect(array_keys($byRule))->toEqualCanonicalizing(array_keys(Rules::all()))
            ->and($byRule['duplicate_names'][0]->message)->toContain('"payment.charge" is declared 2 times')
            ->and($byRule['duplicate_names'][0]->isError())->toBeTrue()
            ->and($byRule['name_pattern'][0]->point)->toBe('BadName')
            ->and($byRule['missing_escalation'][0]->point)->toBe('payment.refund')
            ->and($byRule['catch_all_without_escalation'][0]->point)->toBe('filing.submit')
            ->and($byRule['catch_all_without_escalation'][0]->level)->toBe(Finding::WARNING)
            ->and($byRule['critical_namespace_uncontrolled'])->toHaveCount(1)
            ->and($byRule['critical_namespace_uncontrolled'][0]->message)->toContain('Uncontrolled sits in a critical namespace')
            ->and($byRule['dynamic_name'][0]->file)->toEndWith('InlineCharger.php')
            ->and($byRule['unreadable_control'][0]->point)->toBe('payment.capture')
            ->and($inventory->hasErrors())->toBeTrue();
    });

    it('lets rules be switched off in config', function (): void {
        config()->set('monitor.inventory.rules', ['duplicate_names' => false, 'missing_escalation' => false]);

        $rules = array_map(fn (Finding $r): string => $r->rule, resolve(Discovery::class)->build()->findings());

        expect($rules)->not->toContain('duplicate_names')->not->toContain('missing_escalation')->toContain('name_pattern');
    });

    it('skips the critical namespace rule when none are configured, and unknown paths', function (): void {
        config()->set('monitor.critical_namespaces', []);

        expect(resolve(Discovery::class)->build()->findings())->each->not->toHaveProperty('rule', 'critical_namespace_uncontrolled')
            ->and(resolve(Discovery::class)->build(['/definitely/not/here'])->points)->toBe([]);
    });

    it('reads a file without executing it and survives a syntax error', function (): void {
        $scanned = resolve(AstScanner::class)->scan(workbenchPath().'/ControlPoints/Payments/InlineCharger.php');

        expect($scanned->classes)->toBe([InlineCharger::class])
            ->and($scanned->inlinePoints)->toHaveCount(3)
            ->and($scanned->inlinePoints[0]['name'])->toBe('payment.inline')
            ->and($scanned->inlinePoints[1]['name'])->toBe('payment.direct')
            ->and($scanned->inlinePoints[2]['name'])->toBeNull();

        $broken = tempnam(sys_get_temp_dir(), 'monitor').'.php';
        file_put_contents($broken, '<?php class {');
        expect(resolve(AstScanner::class)->scan($broken)->classes)->toBe([]);
        unlink($broken);

        expect(resolve(AstScanner::class)->scan('/nope/nothing.php')->classes)->toBe([]);
    });
});

describe('reports', function (): void {
    it('renders json and sarif', function (): void {
        $inventory = resolve(Discovery::class)->build();

        $json = json_decode((new JsonReport)->render($inventory), true);
        $sarif = json_decode((new SarifReport)->render($inventory, workbenchPath()), true);

        expect($json['points'])->toHaveCount(10)->and($json['findings'])->not->toBeEmpty()
            ->and($sarif['version'])->toBe('2.1.0')
            ->and($sarif['runs'][0]['tool']['driver']['rules'])->toHaveCount(count(Rules::all()))
            ->and($sarif['runs'][0]['results'][0]['ruleId'])->toBeString()
            ->and($sarif['runs'][0]['results'][0]['locations'][0]['physicalLocation']['artifactLocation']['uri'])->toStartWith('ControlPoints/');
    });

    it('explains a point in prose', function (): void {
        $inventory = resolve(Discovery::class)->build();
        $explainer = resolve(Explainer::class);

        $charge = $explainer->explain($inventory->find('payment.charge'));
        $inline = $explainer->explain($inventory->find('payment.inline'));
        $filing = $explainer->explain($inventory->find('filing.submit'));

        expect($charge)->toContain('payment.charge is a control point class')
            ->toContain('with the "external" profile')
            ->toContain('the "stripe" breaker opens after')
            ->toContain('retry 2 time(s)')
            ->toContain('the result must satisfy "charge must be settled"')
            ->toContain('It recovers from Workbench\\Monitor\\Support\\CardDeclined')
            ->toContain('PagePayments is called when it escalates')
            ->and($inline)->toContain('declared inline')->toContain('Only its name is known statically')
            ->and($filing)->toContain('a database transaction retried 1 time(s)')->toContain('should finish within 5s')->toContain('(a catch-all)')->toContain('Nothing is told when it escalates');
    });

    it('adds history from the store when it is enabled', function (): void {
        config()->set('monitor.records.store.enabled', true);
        (require __DIR__.'/../../database/migrations/create_monitor_outcomes_table.php')->up();
        ChargeCard::run(1, 1);
        resolve(StoreOutcomes::class)->flush();

        $point = resolve(Discovery::class)->build()->find('payment.charge');

        expect(resolve(Explainer::class)->explain($point, resolve(OutcomeStore::class)))->toContain('Last 24 hours: 1 succeeded')
            ->and(resolve(Explainer::class)->explain(resolve(Discovery::class)->build()->find('payment.refund'), resolve(OutcomeStore::class)))->toContain('no runs in the last 24 hours');
    });
});

describe('commands', function (): void {
    it('lists points as a table and fails --check on errors', function (): void {
        $this->artisan('monitor:points')->expectsOutputToContain('payment.charge')->expectsOutputToContain('control point(s)')->assertSuccessful();
        $this->artisan('monitor:points', ['--check' => true])->assertFailed();
        $this->artisan('monitor:points', ['--format' => 'json'])->expectsOutputToContain('"findings"')->assertSuccessful();
        $this->artisan('monitor:points', ['--format' => 'sarif'])->expectsOutputToContain('sarif-2.1.0')->assertSuccessful();
        $this->artisan('monitor:points', ['--path' => ['/nowhere']])->expectsOutputToContain('No control points found')->assertSuccessful();
    });

    it('passes --check on a clean tree', function (): void {
        config()->set('monitor.critical_namespaces', []);
        config()->set('monitor.inventory.rules', ['duplicate_names' => false, 'name_pattern' => false, 'missing_escalation' => false, 'unreadable_control' => false]);

        $this->artisan('monitor:points', ['--check' => true])->assertSuccessful();
    });

    it('shows last seen from the store', function (): void {
        config()->set('monitor.records.store.enabled', true);
        (require __DIR__.'/../../database/migrations/create_monitor_outcomes_table.php')->up();
        ChargeCard::run(1, 1);
        resolve(StoreOutcomes::class)->flush();

        $this->artisan('monitor:points')->expectsOutputToContain('succeeded @')->assertSuccessful();
    });

    it('explains a point or says it does not exist', function (): void {
        $this->artisan('monitor:explain', ['point' => 'payment.charge'])->expectsOutputToContain('is a control point class')->assertSuccessful();
        $this->artisan('monitor:explain', ['point' => 'payment.charge', '--json' => true])->expectsOutputToContain('"form": "class"')->assertSuccessful();
        $this->artisan('monitor:explain', ['point' => 'nope.nope'])->assertFailed();
    });

    it('generates a control point class and its test', function (): void {
        $class = app_path('ControlPoints/Payments/RefundCard.php');
        $test = base_path('tests/Feature/ControlPoints/Payments/RefundCardTest.php');
        File::delete([$class, $test]);

        try {
            $this->artisan('make:control-point', ['name' => 'Payments/RefundCard', '--profile' => 'external'])->assertSuccessful();

            expect(File::exists($class))->toBeTrue()->and(File::exists($test))->toBeTrue();

            $source = File::get($class);
            expect($source)->toContain('namespace App\\ControlPoints\\Payments;')
                ->toContain("#[Point('payments.refund_card', profile: 'external')]")
                ->toContain('final class RefundCard extends ControlPoint')
                ->toContain('protected function control(Control $control): void');

            expect(File::get($test))->toContain('use App\\ControlPoints\\Payments\\RefundCard;')->toContain("Monitor::assertSucceeded('payments.refund_card')");

            $this->artisan('make:control-point', ['name' => 'Payments/RefundCard'])->expectsOutputToContain('already exists');
            $this->artisan('make:control-point', ['name' => 'Payments/RefundCard', '--force' => true, '--point' => 'payment.refund', '--no-test' => true])->assertSuccessful();
            expect(File::get($class))->toContain("#[Point('payment.refund')]");
        } finally {
            File::delete([$class, $test]);
            File::deleteDirectory(app_path('ControlPoints'));
            File::deleteDirectory(base_path('tests/Feature/ControlPoints'));
        }
    });
});
