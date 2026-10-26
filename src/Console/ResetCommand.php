<?php

declare(strict_types=1);

namespace Atannex\Limiting\Console;

use Atannex\Limiting\Context;
use Atannex\Limiting\Limiting;
use Illuminate\Console\Command;

final class ResetCommand extends Command
{
    protected $signature = 'limiting:reset {policy : Policy name} {subject? : Subject label, e.g. ip:203.0.113.9 or user:42}';

    protected $description = 'Clear a subject\'s counters, cooldowns and strikes on a policy';

    public function handle(Limiting $limiting): int
    {
        $subject = $this->argument('subject');
        $label = $subject === null ? null : $limiting->subjects()->normalizeLabel((string) $subject);

        $limiting->for((string) $this->argument('policy'), Context::of($label))->reset();
        $this->info('Reset '.$this->argument('policy').' for '.($label ?? 'global').'.');

        return self::SUCCESS;
    }
}
