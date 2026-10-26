<?php

declare(strict_types=1);

namespace Atannex\Limiting\Algorithms;

use Atannex\Limiting\Contracts\Algorithm;
use Atannex\Limiting\Limit;

/**
 * Sliding window counter: the current window's count plus the previous
 * window's, weighted by how much of it still overlaps the last `window`
 * seconds. No burst at window edges, constant-size state. The default.
 *
 * It also remembers the last hit of each window. The weighting assumes hits
 * were spread evenly, which overcounts badly for small limits (one hit a
 * minute would wait up to two minutes); knowing when the last hit happened
 * lets it drop a window as soon as all of its hits are truly out of range.
 */
final class SlidingWindow implements Algorithm
{
    public function attempt(?array $state, Limit $limit, float $now, int $cost): Transition
    {
        $w = $limit->window;
        $n = $limit->max;
        [$start, $current, $previous, $last, $previousLast] = $this->roll($state, $w, $now);
        $elapsed = $now - $start;
        $weighted = $this->weighted($previous, $previousLast, $elapsed, $w, $now);
        $estimate = $weighted + $current;
        $ttl = $start + 2 * $w - $now + 1;

        if ($estimate + $cost > $n + 1e-6) {
            $retry = $this->retryAfter($n - $cost, $current, $previous, $last, $previousLast, $start, $elapsed, $w, $now);
            $outcome = new Outcome(false, max(0, (int) floor($n - $estimate + 1e-6)), $this->resetAfter($current, $previous, $last, $previousLast, $now, $w), $retry);

            return Transition::deny($outcome, $state, $ttl);
        }

        $current += $cost;

        return Transition::allow(
            new Outcome(true, max(0, (int) floor($n - $weighted - $current + 1e-6)), $this->resetAfter($current, $previous, $now, $previousLast, $now, $w)),
            ['s' => $start, 'c' => $current, 'p' => $previous, 'l' => $now, 'pl' => $previousLast],
            $ttl,
        );
    }

    public function refund(?array $state, Limit $limit, float $now, int $cost, ?string $lease = null): ?Transition
    {
        [$start, $current, $previous, $last, $previousLast] = $this->roll($state, $limit->window, $now);
        $current = max(0, $current - $cost);

        return Transition::allow(
            new Outcome(true, 0, 0),
            ['s' => $start, 'c' => $current, 'p' => $previous, 'l' => $last, 'pl' => $previousLast],
            $start + 2 * $limit->window - $now + 1,
        );
    }

    /** @return array{0: float, 1: int, 2: int, 3: ?float, 4: ?float} start, current, previous, last hit, previous window's last hit */
    private function roll(?array $state, float $w, float $now): array
    {
        $start = floor($now / $w) * $w;

        if ($state === null) {
            return [$start, 0, 0, null, null];
        }

        $stored = (float) $state['s'];
        $last = isset($state['l']) ? (float) $state['l'] : null;

        if (abs($stored - $start) < 1e-6) {
            return [$start, (int) $state['c'], (int) ($state['p'] ?? 0), $last, isset($state['pl']) ? (float) $state['pl'] : null];
        }

        if (abs($stored - ($start - $w)) < 1e-6) {
            return [$start, 0, (int) $state['c'], null, $last];
        }

        return [$start, 0, 0, null, null];
    }

    private function weighted(int $previous, ?float $previousLast, float $elapsed, float $w, float $now): float
    {
        if ($previous === 0 || ($previousLast !== null && $previousLast <= $now - $w)) {
            return 0.0;
        }

        return $previous * (1 - $elapsed / $w);
    }

    /** Seconds until the estimate leaves room for `$need` more units. */
    private function retryAfter(float $need, int $current, int $previous, ?float $last, ?float $previousLast, float $start, float $elapsed, float $w, float $now): float
    {
        if ($need < 0) {
            return $w;
        }

        $options = [];

        if ($previous > 0 && $current <= $need) {
            // previous * (1 - (elapsed + t) / w) + current <= need
            $t = (1 - ($need - $current) / $previous) * $w - $elapsed;

            if ($t <= $w - $elapsed) {
                $options[] = $t;
            }

            // Or once every hit of the previous window is out of range.
            if ($previousLast !== null) {
                $options[] = $previousLast + $w - $now;
            }
        }

        if ($current > 0) {
            // In the next window the current count is the decaying one,
            // and it is gone entirely once its last hit is out of range.
            $e = max(0.0, (1 - $need / $current) * $w);

            if ($last !== null) {
                $e = min($e, max(0.0, $last - $start));
            }

            $options[] = ($w - $elapsed) + $e;
        }

        return max(0.001, $options === [] ? $w - $elapsed : min($options));
    }

    private function resetAfter(int $current, int $previous, ?float $last, ?float $previousLast, float $now, float $w): float
    {
        if ($current > 0 && $last !== null) {
            return max(0.0, $last + $w - $now);
        }

        if ($previous > 0 && $previousLast !== null) {
            return max(0.0, $previousLast + $w - $now);
        }

        return 0.0;
    }
}
