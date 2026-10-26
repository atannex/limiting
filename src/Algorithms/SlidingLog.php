<?php

declare(strict_types=1);

namespace Atannex\Limiting\Algorithms;

use Atannex\Limiting\Contracts\Algorithm;
use Atannex\Limiting\Limit;

/**
 * Exact: keeps the time of every hit in the last `window` seconds. State
 * grows with the limit, so use it for small limits (logins, codes, sends)
 * where exactness matters most.
 */
final class SlidingLog implements Algorithm
{
    public function attempt(?array $state, Limit $limit, float $now, int $cost): Transition
    {
        $w = $limit->window;
        $entries = $this->live($state, $now, $w);
        $used = array_sum(array_column($entries, 1));

        if ($used + $cost > $limit->max) {
            $outcome = new Outcome(false, max(0, $limit->max - $used), $this->resetAfter($entries, $now, $w), $this->retryAfter($entries, $used + $cost - $limit->max, $cost > $limit->max, $now, $w));

            return Transition::deny($outcome, $state, $w);
        }

        $entries[] = [round($now, 6), $cost];

        return Transition::allow(
            new Outcome(true, $limit->max - $used - $cost, $this->resetAfter($entries, $now, $w)),
            ['e' => $entries],
            $w + 1,
        );
    }

    public function refund(?array $state, Limit $limit, float $now, int $cost, ?string $lease = null): ?Transition
    {
        $entries = $this->live($state, $now, $limit->window);

        while ($cost > 0 && $entries !== []) {
            $last = array_pop($entries);

            if ($last[1] > $cost) {
                $entries[] = [$last[0], $last[1] - $cost];
            }

            $cost -= $last[1];
        }

        return Transition::allow(new Outcome(true, 0, 0), $entries === [] ? null : ['e' => $entries], $limit->window + 1);
    }

    /** @return list<array{0: float, 1: int}> */
    private function live(?array $state, float $now, float $w): array
    {
        return array_values(array_filter(
            array_map(static fn (array $entry): array => [(float) $entry[0], (int) $entry[1]], $state['e'] ?? []),
            static fn (array $entry): bool => $entry[0] > $now - $w,
        ));
    }

    /** @param  list<array{0: float, 1: int}>  $entries */
    private function retryAfter(array $entries, int $excess, bool $never, float $now, float $w): float
    {
        if ($never) {
            return $w;
        }

        $freed = 0;

        foreach ($entries as [$at, $cost]) {
            $freed += $cost;

            if ($freed >= $excess) {
                return max(0.001, $at + $w - $now);
            }
        }

        return $w;
    }

    /** @param  list<array{0: float, 1: int}>  $entries */
    private function resetAfter(array $entries, float $now, float $w): float
    {
        return $entries === [] ? 0.0 : max(0.0, end($entries)[0] + $w - $now);
    }
}
