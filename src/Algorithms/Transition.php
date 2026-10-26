<?php

declare(strict_types=1);

namespace Atannex\Limiting\Algorithms;

/** An outcome plus the state to save for it. */
final class Transition
{
    /** @param  array<string, mixed>|null  $state */
    public function __construct(
        public readonly Outcome $outcome,
        public readonly ?array $state,
        public readonly float $ttl,
        public readonly bool $write = true,
    ) {}

    public static function allow(Outcome $outcome, ?array $state, float $ttl): self
    {
        return new self($outcome, $state, $ttl);
    }

    /** A refusal leaves the stored state as it was. */
    public static function deny(Outcome $outcome, ?array $state, float $ttl): self
    {
        return new self($outcome, $state, $ttl, false);
    }

    /** The tuple Store::update() callbacks return. */
    public function forStore(): array
    {
        return [$this->state, $this->ttl, $this, $this->write];
    }
}
