<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Inventory;

use Illuminate\Support\Facades\Date;
use Kirschbaum\Monitor\Store\OutcomeStore;
use Throwable;

/**
 * Turns a point description into prose a person or an agent can read.
 */
class Explainer
{
    public function explain(PointDescription $point, ?OutcomeStore $store = null): string
    {
        $lines = [];

        $lines[] = $point->isClassForm()
            ? sprintf('%s is a control point class, %s, in the %s domain%s.', $point->name, $point->origin, $point->domain, $point->profile !== null ? " with the \"{$point->profile}\" profile" : '')
            : sprintf('%s is declared inline in %s (%s domain)%s. Only its name is known statically.', $point->name, $point->origin, $point->domain, $point->line !== null ? " at line {$point->line}" : '');

        if ($point->isClassForm()) {
            $lines[] = $point->policies === [] ? 'It runs with no policies.' : 'Policies: '.implode('; ', array_map($this->policy(...), $point->policies)).'.';
            $lines[] = $point->limits === [] ? 'It has no limits.' : 'Limits: '.implode('; ', array_map($this->limit(...), $point->limits)).'.';
            $lines[] = $point->risks === [] ? 'It declares no risks: any failure escalates.' : 'It recovers from '.implode(', ', $point->risks).($point->catchAll ? ' (a catch-all)' : '').'.';
            $lines[] = match (true) {
                $point->escalation === null => 'Nothing is told when it escalates.',
                $point->escalation === 'closure' => 'A closure is called when it escalates.',
                default => sprintf('%s is called when it escalates.', $point->escalation),
            };
        }

        foreach ($point->notes as $note) {
            $lines[] = 'Note: '.$note;
        }

        if ($store instanceof OutcomeStore) {
            $lines[] = $this->history($point, $store);
        }

        return implode("\n", $lines);
    }

    private function history(PointDescription $point, OutcomeStore $store): string
    {
        try {
            $tally = $store->tally($point->name, Date::now()->subDay());
        } catch (Throwable) {
            return 'History: the store could not be read.';
        }

        if ($tally === []) {
            return 'History: no runs in the last 24 hours.';
        }

        $parts = [];

        foreach ($tally as $status => $count) {
            $parts[] = "{$count} {$status}";
        }

        return 'Last 24 hours: '.implode(', ', $parts).'.';
    }

    /**
     * @param  array<string, mixed>  $policy
     */
    private function policy(array $policy): string
    {
        return match ($policy['type'] ?? null) {
            'retry' => sprintf('retry %s time(s) with %sms backoff', $this->str($policy['times'] ?? 0), $this->str($policy['backoff_ms'] ?? 0)),
            'transaction' => sprintf('a database transaction retried %s time(s) on deadlock', $this->str($policy['retries'] ?? 0)),
            'breaker' => sprintf('the "%s" breaker opens after %s failures within %ss for %ss', $this->str($policy['name'] ?? ''), $this->str($policy['after'] ?? ''), $this->str($policy['within'] ?? ''), $this->str($policy['for'] ?? '')),
            default => is_string($policy['type'] ?? null) ? $policy['type'] : 'a custom policy',
        };
    }

    /**
     * @param  array<string, mixed>  $limit
     */
    private function limit(array $limit): string
    {
        return match ($limit['type'] ?? null) {
            'within' => sprintf('should finish within %ss', $this->str($limit['seconds'] ?? '')),
            'attempts' => sprintf('at most %s attempt(s)', $this->str($limit['max'] ?? '')),
            'ensure' => sprintf('the result must satisfy "%s"', $this->str($limit['reason'] ?? '')),
            default => 'a custom limit',
        };
    }

    private function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
