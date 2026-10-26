<?php

declare(strict_types=1);

namespace Atannex\Limiting\Console;

use Atannex\Limiting\Limiting;
use Illuminate\Console\Command;

final class StatsCommand extends Command
{
    protected $signature = 'limiting:stats {--hours=24 : How far back} {--json : Print JSON}';

    protected $description = 'Show refusals per limit and the subjects refused most';

    public function handle(Limiting $limiting): int
    {
        $summary = $limiting->stats()->summary(max(1, (int) $this->option('hours')));

        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($summary['limits'] === []) {
            $this->info('Nothing was refused in the last '.$this->option('hours').' hours.');

            return self::SUCCESS;
        }

        $this->table(['Limit', 'Refused', 'Blocked', 'Jailed', 'Shadow', 'Store down'], array_map(
            fn (string $name, array $buckets) => [$name, $buckets['limit'] ?? 0, $buckets['blocked'] ?? 0, $buckets['jailed'] ?? 0, $buckets['shadow'] ?? 0, $buckets['degraded'] ?? 0],
            array_keys($summary['limits']),
            $summary['limits'],
        ));

        $this->table(['Subject', 'Refusals'], array_map(fn ($subject, $count) => [$subject, $count], array_keys($summary['subjects']), $summary['subjects']));

        return self::SUCCESS;
    }
}
