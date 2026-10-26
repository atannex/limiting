<?php

declare(strict_types=1);

namespace Atannex\Limiting;

/** One limit's answer for one attempt. */
final class Decision
{
    public const LIMIT = 'limit';

    public const BLOCKED = 'blocked';

    public const JAILED = 'jailed';

    public const DENIED = 'denied';

    public const BYPASSED = 'bypassed';

    public const DEGRADED = 'degraded';

    public const UNLIMITED = 'unlimited';

    public function __construct(
        public readonly string $policy,
        public readonly string $limit,
        public readonly bool $allowed,
        public readonly int $max,
        public readonly int $remaining,
        public readonly float $window,
        /** Seconds until the limit is fully restored. */
        public readonly float $resetAfter,
        /** Seconds until a retry can pass. */
        public readonly float $retryAfter,
        public readonly string $subject,
        public readonly string $key = '',
        /** Why: limit, blocked, jailed, denied, bypassed, degraded or unlimited. */
        public readonly string $reason = self::LIMIT,
        public readonly float $delay = 0.0,
        public readonly ?string $lease = null,
        /** Refused in shadow mode: reported, but let through. */
        public readonly bool $shadowed = false,
        public readonly int $cost = 1,
        public readonly string $algorithm = '',
    ) {}

    public function denied(): bool
    {
        return ! $this->allowed;
    }

    /** Decisions that report on a real limit, as opposed to a bypass or a jail. */
    public function measured(): bool
    {
        return in_array($this->reason, [self::LIMIT, self::BLOCKED], true) && $this->max !== PHP_INT_MAX;
    }

    /** Share of the limit still available, 0..1. */
    public function headroom(): float
    {
        if ($this->max <= 0 || $this->max === PHP_INT_MAX) {
            return $this->max === PHP_INT_MAX ? 1.0 : 0.0;
        }

        return $this->remaining / $this->max;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'policy' => $this->policy,
            'limit' => $this->limit,
            'allowed' => $this->allowed,
            'reason' => $this->reason,
            'max' => $this->max,
            'remaining' => $this->remaining,
            'window' => $this->window,
            'reset_after' => round($this->resetAfter, 3),
            'retry_after' => round($this->retryAfter, 3),
            'delay' => round($this->delay, 3),
            'subject' => $this->subject,
            'shadowed' => $this->shadowed,
            'algorithm' => $this->algorithm,
        ];
    }
}
