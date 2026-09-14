<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Inventory\Reports;

use Kirschbaum\Monitor\Inventory\Finding;
use Kirschbaum\Monitor\Inventory\Inventory;
use Kirschbaum\Monitor\Inventory\Rules\Rules;

/**
 * SARIF 2.1.0, so the findings show up as annotations in code scanning.
 */
class SarifReport
{
    public function render(Inventory $inventory, string $basePath): string
    {
        $rules = [];

        foreach (array_keys(Rules::all()) as $name) {
            $rules[] = ['id' => $name, 'name' => $name, 'shortDescription' => ['text' => str_replace('_', ' ', $name)]];
        }

        $results = array_map(fn (Finding $f): array => $this->result($f, $basePath), $inventory->findings());

        return (string) json_encode([
            '$schema' => 'https://json.schemastore.org/sarif-2.1.0.json',
            'version' => '2.1.0',
            'runs' => [[
                'tool' => ['driver' => ['name' => 'monitor:points', 'informationUri' => 'https://github.com/kirschbaum-development/monitor', 'rules' => $rules]],
                'results' => $results,
            ]],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, mixed>
     */
    private function result(Finding $finding, string $basePath): array
    {
        $result = [
            'ruleId' => $finding->rule,
            'level' => $finding->isError() ? 'error' : 'warning',
            'message' => ['text' => $finding->message],
        ];

        if ($finding->file !== null) {
            $uri = str_starts_with($finding->file, $basePath) ? ltrim(substr($finding->file, strlen($basePath)), '/') : $finding->file;
            $location = ['physicalLocation' => ['artifactLocation' => ['uri' => $uri]]];

            if ($finding->line !== null) {
                $location['physicalLocation']['region'] = ['startLine' => $finding->line];
            }

            $result['locations'] = [$location];
        }

        return $result;
    }
}
