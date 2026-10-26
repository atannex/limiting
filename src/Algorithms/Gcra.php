<?php

declare(strict_types=1);

namespace Atannex\Limiting\Algorithms;

use Atannex\Limiting\Contracts\Algorithm;
use Atannex\Limiting\Limit;

/**
 * Generic cell rate algorithm (as in redis-cell and telecom traffic
 * policing): one timestamp of state, the "theoretical arrival time". Gives
 * the exact steady rate of a token bucket with exact retry times and no
 * refill arithmetic. `burst` (default: the limit) requests may arrive at once.
 */
final class Gcra implements Algorithm
{
    public function attempt(?array $state, Limit $limit, float $now, int $cost): Transition
    {
        $interval = $limit->window / $limit->max;
        $tolerance = $interval * ($limit->burst ?? $limit->max);
        $tat = max((float) ($state['tat'] ?? $now), $now);
        $newTat = $tat + $interval * $cost;
        $allowAt = $newTat - $tolerance;

        if ($allowAt > $now + 1e-6) {
            $remaining = max(0, (int) floor(($tolerance - ($tat - $now)) / $interval + 1e-6));
            $retry = $interval * $cost > $tolerance ? $limit->window : $allowAt - $now;

            return Transition::deny(new Outcome(false, $remaining, $tat - $now, max(0.001, $retry)), $state, $tat - $now + 1);
        }

        $remaining = max(0, (int) floor(($tolerance - ($newTat - $now)) / $interval + 1e-6));

        return Transition::allow(new Outcome(true, $remaining, $newTat - $now), ['tat' => $newTat], $newTat - $now + 1);
    }

    public function refund(?array $state, Limit $limit, float $now, int $cost, ?string $lease = null): ?Transition
    {
        if ($state === null) {
            return null;
        }

        $tat = max($now, (float) $state['tat'] - ($limit->window / $limit->max) * $cost);

        return Transition::allow(new Outcome(true, 0, 0), ['tat' => $tat], $tat - $now + 1);
    }
}
