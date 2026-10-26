<?php

declare(strict_types=1);

namespace Atannex\Limiting\Algorithms;

use Atannex\Limiting\Contracts\Algorithm;
use Atannex\Limiting\Limit;

/**
 * Leaky bucket as a queue: callers are spaced evenly at `max / window` a
 * second. Instead of refusing a caller that comes too soon, it tells them how
 * long to wait (Outcome::$delay); only callers who would wait longer than
 * `maxDelay` are refused. Smooths traffic to fragile upstreams.
 */
final class LeakyBucket implements Algorithm
{
    public function attempt(?array $state, Limit $limit, float $now, int $cost): Transition
    {
        $interval = $limit->window / $limit->max;
        $next = max((float) ($state['n'] ?? 0), $now);
        $delay = $next - $now;

        if ($delay > $limit->maxDelay + 1e-6) {
            return Transition::deny(new Outcome(false, 0, $delay, max(0.001, $delay - $limit->maxDelay)), $state, $delay + 1);
        }

        $after = $next + $interval * $cost;
        $queued = $after - $now;
        $remaining = $queued > $limit->maxDelay ? 0 : (int) floor(($limit->maxDelay - $queued) / $interval + 1e-6) + 1;

        return Transition::allow(new Outcome(true, $remaining, $queued, 0.0, $delay), ['n' => $after], $queued + 1);
    }

    public function refund(?array $state, Limit $limit, float $now, int $cost, ?string $lease = null): ?Transition
    {
        if ($state === null) {
            return null;
        }

        $next = max($now, (float) $state['n'] - ($limit->window / $limit->max) * $cost);

        return Transition::allow(new Outcome(true, 0, 0), ['n' => $next], $next - $now + 1);
    }
}
