<?php

declare(strict_types=1);

namespace Atannex\Limiting\Console;

use Atannex\Limiting\Context;
use Atannex\Limiting\Limiting;
use Illuminate\Console\Command;

final class InspectCommand extends Command
{
    use Concerns;

    protected $signature = 'limiting:inspect {policy : Policy name} {subject? : Subject label, e.g. ip:203.0.113.9, user:42, "email:bob@example.com|ip:203.0.113.9"}';

    protected $description = 'Show where a subject stands on each limit of a policy, without spending anything';

    public function handle(Limiting $limiting): int
    {
        $subject = $this->argument('subject');
        $context = Context::of($subject === null ? null : $limiting->subjects()->normalizeLabel((string) $subject));
        $limiter = $limiting->for((string) $this->argument('policy'), $context);
        $rows = [];

        foreach ($limiter->inspect() as $row) {
            $decision = $row['decision'];
            $rows[] = [
                $row['limit']->name,
                $row['limit']->describe(),
                $row['algorithm'],
                $decision->allowed ? '<info>'.($decision->remaining + $decision->cost).' left</info>' : '<error>refused</error> for '.$this->seconds($decision->retryAfter),
                $this->seconds($decision->resetAfter),
                $row['blocked_until'] ? 'until '.$this->at($row['blocked_until']) : '-',
            ];
        }

        $label = $subject ?? 'global';
        $this->line("Policy <comment>{$limiter->policy->name}</comment> for <comment>{$label}</comment>");
        $this->table(['Limit', 'Rate', 'Algorithm', 'Now', 'Full in', 'Blocked'], $rows);

        if ($jailed = $limiting->jail()->find($limiting->subjects()->normalizeLabel($label))) {
            $this->warn("Jailed until {$this->at($jailed['until'])}: {$jailed['reason']}");
        }

        return self::SUCCESS;
    }
}
