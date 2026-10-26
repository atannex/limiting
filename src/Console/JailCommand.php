<?php

declare(strict_types=1);

namespace Atannex\Limiting\Console;

use Atannex\Limiting\Limiting;
use Atannex\Limiting\Support\Duration;
use Illuminate\Console\Command;

final class JailCommand extends Command
{
    use Concerns;

    protected $signature = 'limiting:jail {subject : An IP, CIDR-free label like ip:203.0.113.9 or user:42} {--for=1h : How long} {--reason=manual : Why}';

    protected $description = 'Jail a subject: every policy and the shield refuse it';

    public function handle(Limiting $limiting): int
    {
        $label = $limiting->subjects()->normalizeLabel((string) $this->argument('subject'));
        $record = $limiting->jail()->lock($label, Duration::seconds((string) $this->option('for')), (string) $this->option('reason'));

        $this->info("Jailed {$label} until {$this->at($record['until'])}.");

        return self::SUCCESS;
    }
}
