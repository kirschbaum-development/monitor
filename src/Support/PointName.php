<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Support;

use Illuminate\Support\Facades\Config;
use Kirschbaum\Monitor\Exceptions\InvalidPointName;

/**
 * @internal
 */
class PointName
{
    /**
     * The string form of a name given as a string or a backed enum.
     */
    public static function of(string|\BackedEnum $name): string
    {
        return $name instanceof \BackedEnum ? (string) $name->value : $name;
    }

    public static function validate(string $name): string
    {
        if (! self::isValid($name)) {
            throw new InvalidPointName(sprintf(
                'Control point name [%s] does not match the pattern [%s].',
                $name,
                Config::string('monitor.point_name_pattern'),
            ));
        }

        return $name;
    }

    public static function isValid(string $name): bool
    {
        $pattern = Config::string('monitor.point_name_pattern', '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/');

        return preg_match($pattern, $name) === 1;
    }
}
