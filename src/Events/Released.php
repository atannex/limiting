<?php

declare(strict_types=1);

namespace Atannex\Limiting\Events;

/** A subject was let out of jail before its time. */
final class Released
{
    public function __construct(public readonly string $subject) {}
}
