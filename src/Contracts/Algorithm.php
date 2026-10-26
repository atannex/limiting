<?php

declare(strict_types=1);

namespace Atannex\Limiting\Contracts;

use Atannex\Limiting\Algorithms\Transition;
use Atannex\Limiting\Limit;

/**
 * A rate limiting algorithm, written as a pure state transition.
 *
 * The store hands over the stored state (null when there is none) inside an
 * atomic section, and saves the state that comes back. Algorithms never touch
 * the store or the clock themselves, which keeps them exact and testable, and
 * lets every store run every algorithm.
 */
interface Algorithm
{
    /** Try to spend `$cost` units at `$now`. */
    public function attempt(?array $state, Limit $limit, float $now, int $cost): Transition;

    /**
     * Give back what an earlier attempt spent: used when a later limit of the
     * same policy refuses, and to release concurrency leases.
     */
    public function refund(?array $state, Limit $limit, float $now, int $cost, ?string $lease = null): ?Transition;
}
