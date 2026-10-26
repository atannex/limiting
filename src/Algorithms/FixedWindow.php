<?php

declare(strict_types=1);

namespace Atannex\Limiting\Algorithms;

use Atannex\Limiting\Contracts\Algorithm;
use Atannex\Limiting\Limit;

/**
 * Counts per window aligned to the clock (every minute on the minute).
 * Cheapest, but allows up to twice the limit across a window edge; prefer
 * the sliding window unless that doesn't matter.
 */
final class FixedWindow implements Algorithm
{
    public function attempt(?array $state, Limit $limit, float $now, int $cost): Transition
    {
        [$start, $end] = $this->window($limit, $now);
        $count = $this->count($state, $start);
        $reset = $end - $now;

        if ($count + $cost > $limit->max) {
            return Transition::deny(new Outcome(false, max(0, $limit->max - $count), $reset, $reset), $state, $reset);
        }

        $count += $cost;

        return Transition::allow(new Outcome(true, $limit->max - $count, $reset), ['s' => $start, 'c' => $count], $reset + 1);
    }

    public function refund(?array $state, Limit $limit, float $now, int $cost, ?string $lease = null): ?Transition
    {
        [$start, $end] = $this->window($limit, $now);
        $count = max(0, $this->count($state, $start) - $cost);

        return Transition::allow(new Outcome(true, $limit->max - $count, $end - $now), ['s' => $start, 'c' => $count], $end - $now + 1);
    }

    /** @return array{0: float, 1: float} */
    public function window(Limit $limit, float $now): array
    {
        $start = floor($now / $limit->window) * $limit->window;

        return [$start, $start + $limit->window];
    }

    private function count(?array $state, float $start): int
    {
        return $state !== null && abs((float) $state['s'] - $start) < 1e-6 ? (int) $state['c'] : 0;
    }
}
