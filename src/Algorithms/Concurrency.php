<?php

declare(strict_types=1);

namespace Atannex\Limiting\Algorithms;

use Atannex\Limiting\Contracts\Algorithm;
use Atannex\Limiting\Limit;

/**
 * At most `max` at once. Each admitted caller holds a lease until it is
 * released, or until `lease` seconds pass, so a crashed worker can't hold a
 * slot forever.
 */
final class Concurrency implements Algorithm
{
    public function attempt(?array $state, Limit $limit, float $now, int $cost): Transition
    {
        $leases = $this->live($state, $now);
        $held = count($leases);

        if ($held + 1 > $limit->max) {
            $retry = $leases === [] ? $limit->lease : max(0.001, min($leases) - $now);

            return Transition::deny(new Outcome(false, 0, $retry, $retry), $state, $limit->lease + 1);
        }

        $id = bin2hex(random_bytes(8));
        $leases[$id] = $now + $limit->lease;

        return Transition::allow(new Outcome(true, $limit->max - $held - 1, 0.0, 0.0, 0.0, $id), ['l' => $leases], $limit->lease + 1);
    }

    public function refund(?array $state, Limit $limit, float $now, int $cost, ?string $lease = null): ?Transition
    {
        $leases = $this->live($state, $now);

        if ($lease === null || ! isset($leases[$lease])) {
            return null;
        }

        unset($leases[$lease]);

        return Transition::allow(new Outcome(true, $limit->max - count($leases), 0), $leases === [] ? null : ['l' => $leases], $limit->lease + 1);
    }

    /** @return array<string, float> */
    private function live(?array $state, float $now): array
    {
        return array_filter(
            array_map('floatval', (array) ($state['l'] ?? [])),
            static fn (float $expires): bool => $expires > $now,
        );
    }
}
