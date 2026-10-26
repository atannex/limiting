<?php

declare(strict_types=1);

namespace Atannex\Limiting\Events;

use Throwable;

/** The store failed; the limit failed open (let through) or closed (refused). */
final class StoreFailed
{
    public function __construct(
        public readonly Throwable $exception,
        public readonly string $policy,
        public readonly string $limit,
        public readonly bool $failedClosed,
    ) {}
}
