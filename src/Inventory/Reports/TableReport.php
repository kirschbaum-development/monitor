<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Inventory\Reports;

use Illuminate\Console\OutputStyle;
use Kirschbaum\Monitor\Inventory\Finding;
use Kirschbaum\Monitor\Inventory\Inventory;
use Kirschbaum\Monitor\Inventory\PointDescription;

class TableReport
{
    /**
     * @param  array<string, array{ended_at: string, status: string}>  $lastSeen
     */
    public function render(OutputStyle $output, Inventory $inventory, array $lastSeen = []): void
    {
        if ($inventory->points === []) {
            $output->writeln('<comment>No control points found.</comment>');
        } else {
            $output->table(
                ['Point', 'Form', 'Domain', 'Origin', 'Profile', 'Policies', 'Risks', 'Escalation', 'Last seen'],
                array_map(fn (PointDescription $p): array => $this->row($p, $lastSeen[$p->name] ?? null), $inventory->points),
            );
        }

        foreach ($inventory->findings() as $finding) {
            $tag = $finding->isError() ? 'error' : 'comment';
            $where = $finding->file !== null ? ' <fg=gray>'.basename($finding->file).($finding->line !== null ? ':'.$finding->line : '').'</>' : '';
            $output->writeln(sprintf('<%s>%s</%s> [%s] %s%s', $tag, strtoupper($finding->level), $tag, $finding->rule, $finding->message, $where));
        }

        $errors = count(array_filter($inventory->findings(), fn (Finding $f): bool => $f->isError()));
        $warnings = count($inventory->findings()) - $errors;

        $output->writeln(sprintf('%d control point(s), %d error(s), %d warning(s).', count($inventory->points), $errors, $warnings));
    }

    /**
     * @param  array{ended_at: string, status: string}|null  $seen
     * @return list<string>
     */
    private function row(PointDescription $point, ?array $seen): array
    {
        $policies = implode(', ', array_map(fn (array $p): string => is_string($p['type'] ?? null) ? $p['type'] : '?', $point->policies));
        $risks = implode(', ', array_map(fn (string $r): string => substr($r, (int) strrpos('\\'.$r, '\\')), $point->risks));

        if ($point->catchAll) {
            $risks .= ' (catch-all)';
        }

        $escalation = $point->escalation === null ? '<fg=red>none</>' : ($point->escalation === 'closure' ? 'closure' : substr($point->escalation, (int) strrpos('\\'.$point->escalation, '\\')));

        return [
            $point->name,
            $point->form,
            $point->domain,
            substr($point->origin, (int) strrpos('\\'.$point->origin, '\\')),
            $point->profile ?? '',
            $point->isClassForm() ? $policies : '<fg=gray>not inspected</>',
            $point->isClassForm() ? trim($risks) : '<fg=gray>not inspected</>',
            $point->isClassForm() ? $escalation : '<fg=gray>not inspected</>',
            $seen === null ? '' : $seen['status'].' @ '.$seen['ended_at'],
        ];
    }
}
