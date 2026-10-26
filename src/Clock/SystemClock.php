<?php

declare(strict_types=1);

namespace Atannex\Limiting\Clock;

use Atannex\Limiting\Contracts\Clock;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Sleep;

/**
 * The application clock. It follows Carbon's test time, so `$this->travel()`
 * and `Sleep::fake()` move limits along in tests.
 */
final class SystemClock implements Clock
{
    public function now(): float
    {
        return (float) Date::now()->format('U.u');
    }

    public function sleep(float $seconds): void
    {
        if ($seconds > 0) {
            Sleep::usleep((int) ceil($seconds * 1_000_000));
        }
    }
}
