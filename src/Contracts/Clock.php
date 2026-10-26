<?php

declare(strict_types=1);

namespace Atannex\Limiting\Contracts;

interface Clock
{
    /** Unix time in seconds, with microseconds. */
    public function now(): float;

    public function sleep(float $seconds): void;
}
