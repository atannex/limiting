<?php

declare(strict_types=1);

namespace Atannex\Limiting\Console;

use Atannex\Limiting\Limiting;
use Illuminate\Console\Command;

final class ReleaseCommand extends Command
{
    protected $signature = 'limiting:release {subject : The jailed label or IP} {--pardon : Also forget earlier sentences, so the next one starts short}';

    protected $description = 'Let a subject out of jail';

    public function handle(Limiting $limiting): int
    {
        $label = $limiting->subjects()->normalizeLabel((string) $this->argument('subject'));
        $released = $this->option('pardon') ? $limiting->jail()->pardon($label) : $limiting->jail()->release($label);

        $released ? $this->info("Released {$label}.") : $this->line("{$label} was not jailed.");

        return self::SUCCESS;
    }
}
