<?php

declare(strict_types=1);

namespace Atannex\Limiting\Clock;

use Atannex\Limiting\Contracts\Clock;
use Atannex\Limiting\Stores\RedisStore;

/**
 * Redis server time, so a fleet of app servers agrees on "now" even when
 * their own clocks drift. Read once per evaluation and cached briefly.
 */
final class StoreClock implements Clock
{
    private ?float $offset = null;

    private float $measuredAt = 0.0;

    public function __construct(private readonly RedisStore $store, private readonly Clock $fallback) {}

    public function now(): float
    {
        $local = microtime(true);

        if ($this->offset === null || $local - $this->measuredAt > 1.0) {
            try {
                $this->offset = $this->store->time() - $local;
                $this->measuredAt = $local;
            } catch (\Throwable) {
                return $this->fallback->now();
            }
        }

        return $local + $this->offset;
    }

    public function sleep(float $seconds): void
    {
        $this->fallback->sleep($seconds);
    }
}
