<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Support;

use Illuminate\Support\Facades\Config;
use Kirschbaum\Monitor\Exceptions\InvalidPointName;

final class PointName
{
    public static function validate(string $name): string
    {
        if (! self::isValid($name)) {
            throw new InvalidPointName(sprintf(
                'Control point name "%s" does not match the pattern %s.',
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
