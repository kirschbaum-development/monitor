<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Support;

final class Lists
{
    /**
     * The array-valued items of a value, as a list of string-keyed arrays.
     *
     * @return list<array<string, mixed>>
     */
    public static function ofArrays(mixed $value): array
    {
        $out = [];

        foreach (is_array($value) ? $value : [] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $row = [];

            foreach ($item as $key => $itemValue) {
                $row[(string) $key] = $itemValue;
            }

            $out[] = $row;
        }

        return $out;
    }
}
