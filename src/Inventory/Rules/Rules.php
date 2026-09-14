<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Inventory\Rules;

use Illuminate\Support\Facades\Config;
use Kirschbaum\Monitor\Contracts\Rule;

class Rules
{
    /**
     * @return array<string, class-string<Rule>>
     */
    public static function all(): array
    {
        return [
            'duplicate_names' => DuplicateNames::class,
            'name_pattern' => NamePattern::class,
            'missing_escalation' => MissingEscalation::class,
            'catch_all_without_escalation' => CatchAllWithoutEscalation::class,
            'critical_namespace_uncontrolled' => CriticalNamespaceUncontrolled::class,
            'dynamic_name' => DynamicName::class,
            'unreadable_control' => UnreadableControl::class,
        ];
    }

    /**
     * The rules config('monitor.inventory.rules') leaves enabled; all by default.
     *
     * @return list<Rule>
     */
    public static function configured(): array
    {
        $toggles = Config::array('monitor.inventory.rules', []);
        $rules = [];

        foreach (self::all() as $name => $class) {
            if (($toggles[$name] ?? true) === false) {
                continue;
            }

            $rules[] = new $class;
        }

        return $rules;
    }
}
