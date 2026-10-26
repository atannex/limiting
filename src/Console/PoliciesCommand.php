<?php

declare(strict_types=1);

namespace Atannex\Limiting\Console;

use Atannex\Limiting\Limit;
use Atannex\Limiting\Limiting;
use Illuminate\Console\Command;

final class PoliciesCommand extends Command
{
    use Concerns;

    protected $signature = 'limiting:policies {--json : Print JSON}';

    protected $description = 'List rate limiting policies and their limits';

    public function handle(Limiting $limiting): int
    {
        $default = (string) config('limiting.default_algorithm', Limit::SLIDING_WINDOW);
        $rows = [];

        foreach ($limiting->policies() as $name => $policy) {
            $limits = $policy->fixedLimits();

            if ($limits === null) {
                $rows[] = [$name, '(decided per request)', '', '', '', ''];

                continue;
            }

            foreach ($limits as $limit) {
                $rows[] = [
                    $name,
                    $limit->name,
                    $limit->describe(),
                    $limit->algorithmOr($default).($limit->burst ? " (burst {$limit->burst})" : ''),
                    $limit->by === [] ? config('limiting.default_by', 'actor') : implode('|', array_map(fn ($key) => is_string($key) ? $key : 'closure', $limit->by)),
                    implode(', ', array_filter([
                        $limit->countsResponses() ? 'counts matching responses' : null,
                        $limit->blockFor ? 'block '.$this->seconds($limit->blockFor) : null,
                        $limit->jail ? 'jail after '.$limit->jail['after'] : null,
                        $limit->fail === 'closed' ? 'fails closed' : null,
                        $limit->shadow ? 'shadow' : null,
                    ])),
                ];
            }
        }

        if ($this->option('json')) {
            $this->line(json_encode(array_map(fn ($row) => array_combine(['policy', 'limit', 'rate', 'algorithm', 'by', 'options'], $row), $rows), JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->table(['Policy', 'Limit', 'Rate', 'Algorithm', 'By', 'Options'], $rows);

        return self::SUCCESS;
    }
}
