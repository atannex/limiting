<?php

declare(strict_types=1);

namespace Atannex\Limiting\Events;

/** A subject was jailed: every policy refuses it until `$until`. */
final class Jailed
{
    public function __construct(
        public readonly string $subject,
        public readonly float $until,
        public readonly float $seconds,
        public readonly string $reason,
        public readonly ?string $policy = null,
        /** How many sentences this subject has had within jail.memory, this one included. */
        public readonly int $sentence = 1,
    ) {}
}
