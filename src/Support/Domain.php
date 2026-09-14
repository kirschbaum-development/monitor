<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Support;

use Illuminate\Support\Facades\Config;

/**
 * Resolves the domain a class belongs to from its namespace.
 *
 * The map in config('monitor.domains.map') is tried in order. A null value
 * takes the namespace segment right after the prefix; a string value is used
 * as-is. Nothing matching yields the configured fallback.
 *
 * @internal
 */
class Domain
{
    public static function resolve(string $class): string
    {
        /** @var array<string, string|null> $map */
        $map = Config::array('monitor.domains.map', []);

        foreach ($map as $prefix => $domain) {
            if (! str_starts_with($class, $prefix)) {
                continue;
            }

            if (is_string($domain) && $domain !== '') {
                return $domain;
            }

            $rest = substr($class, strlen($prefix));
            $segments = explode('\\', $rest);

            // A class directly under the prefix has no domain segment of its own.
            if (count($segments) > 1 && $segments[0] !== '') {
                return $segments[0];
            }
        }

        return Config::string('monitor.domains.fallback', 'App');
    }
}
