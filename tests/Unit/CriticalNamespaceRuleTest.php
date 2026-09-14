<?php

declare(strict_types=1);

namespace Tests\Unit;

use Kirschbaum\Monitor\Analysis\PhpStan\CriticalNamespaceRule;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<CriticalNamespaceRule>
 */
final class CriticalNamespaceRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new CriticalNamespaceRule(['Workbench\\Monitor\\ControlPoints']);
    }

    public function test_it_flags_only_classes_that_are_neither_control_points_nor_call_one(): void
    {
        $fixtures = realpath(__DIR__.'/../../workbench/app/ControlPoints');

        $this->analyse([
            $fixtures.'/Filings/Uncontrolled.php',
            $fixtures.'/Filings/Misnamed.php',
            $fixtures.'/Filings/FilingStatus.php',
            $fixtures.'/Filings/SubmitFiling.php',
            $fixtures.'/Payments/InlineCharger.php',
            $fixtures.'/Escalations/PagePayments.php',
        ], [
            [
                'Workbench\Monitor\ControlPoints\Filings\Uncontrolled sits in a critical namespace but is not a control point and calls none.',
                10,
                'Extend Kirschbaum\Monitor\ControlPoint, or wrap the operation in Monitor::control().',
            ],
            [
                'Workbench\Monitor\ControlPoints\Filings\GhostFiling sits in a critical namespace but is not a control point and calls none.',
                11,
                'Extend Kirschbaum\Monitor\ControlPoint, or wrap the operation in Monitor::control().',
            ],
        ]);
    }

    public function test_it_is_silent_without_namespaces(): void
    {
        $rule = new CriticalNamespaceRule([]);

        self::assertSame(InClassNode::class, $rule->getNodeType());
    }
}
