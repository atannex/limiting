<?php

declare(strict_types=1);

namespace Atannex\Limiting\Algorithms;

/** What one algorithm says about one attempt. */
final class Outcome
{
    public function __construct(
        public readonly bool $allowed,
        public readonly int $remaining,
        /** Seconds until the limit is fully restored. */
        public readonly float $resetAfter,
        /** Seconds until the same attempt would pass (0 when it passed). */
        public readonly float $retryAfter = 0.0,
        /** Seconds the caller should wait before going ahead (leaky bucket shaping). */
        public readonly float $delay = 0.0,
        /** Concurrency lease to release when the work is done. */
        public readonly ?string $lease = null,
    ) {}
}
