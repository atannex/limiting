<?php

declare(strict_types=1);

namespace Atannex\Limiting\Algorithms;

use Atannex\Limiting\Contracts\Algorithm;
use Atannex\Limiting\Limit;

/**
 * A bucket of `burst` tokens (default: the limit) refilled continuously at
 * `max / window` a second. Allows short bursts while holding the long-run
 * rate; the natural fit for APIs.
 */
final class TokenBucket implements Algorithm
{
    public function attempt(?array $state, Limit $limit, float $now, int $cost): Transition
    {
        $capacity = (float) ($limit->burst ?? $limit->max);
        $rate = $limit->max / $limit->window;
        $tokens = $this->tokens($state, $capacity, $rate, $now);
        $full = ($capacity - $tokens) / $rate;

        if ($cost > $tokens + 1e-6) {
            $retry = $cost > $capacity ? $limit->window : ($cost - $tokens) / $rate;

            return Transition::deny(new Outcome(false, (int) floor($tokens), $full, max(0.001, $retry)), $state, $full + 1);
        }

        $tokens -= $cost;
        $full = ($capacity - $tokens) / $rate;

        return Transition::allow(new Outcome(true, (int) floor($tokens + 1e-6), $full), ['t' => $tokens, 'ts' => $now], $full + 1);
    }

    public function refund(?array $state, Limit $limit, float $now, int $cost, ?string $lease = null): ?Transition
    {
        $capacity = (float) ($limit->burst ?? $limit->max);
        $rate = $limit->max / $limit->window;
        $tokens = min($capacity, $this->tokens($state, $capacity, $rate, $now) + $cost);

        return Transition::allow(new Outcome(true, (int) $tokens, 0), ['t' => $tokens, 'ts' => $now], ($capacity - $tokens) / $rate + 1);
    }

    private function tokens(?array $state, float $capacity, float $rate, float $now): float
    {
        if ($state === null) {
            return $capacity;
        }

        return min($capacity, (float) $state['t'] + max(0.0, $now - (float) $state['ts']) * $rate);
    }
}
