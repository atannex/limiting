<?php

declare(strict_types=1);

namespace Atannex\Limiting\Clock;

use Atannex\Limiting\Contracts\Clock;

/** A clock that only moves when told to; sleeping moves it. */
final class FrozenClock implements Clock
{
    public function __construct(private float $now = 1_700_000_000.0) {}

    public function now(): float
    {
        return $this->now;
    }

    public function sleep(float $seconds): void
    {
        $this->advance($seconds);
    }

    public function advance(float $seconds): self
    {
        $this->now += max(0.0, $seconds);

        return $this;
    }

    public function set(float $now): self
    {
        $this->now = $now;

        return $this;
    }
}
