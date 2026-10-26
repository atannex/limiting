<?php

declare(strict_types=1);

namespace Atannex\Limiting\Console;

use Atannex\Limiting\Limiting;
use Illuminate\Console\Command;

final class JailedCommand extends Command
{
    use Concerns;

    protected $signature = 'limiting:jailed {--json : Print JSON}';

    protected $description = 'List jailed subjects';

    public function handle(Limiting $limiting): int
    {
        $records = $limiting->jail()->all();

        if ($this->option('json')) {
            $this->line(json_encode($records, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($records === []) {
            $this->info('Nobody is jailed.');

            return self::SUCCESS;
        }

        $now = $limiting->clock()->now();

        $this->table(['Subject', 'Until', 'Left', 'Reason', 'Policy', 'Sentence'], array_map(fn (array $record) => [
            $record['subject'],
            $this->at($record['until']),
            $this->seconds($record['until'] - $now),
            $record['reason'],
            $record['policy'] ?? '-',
            '#'.$record['sentence'],
        ], $records));

        return self::SUCCESS;
    }
}
