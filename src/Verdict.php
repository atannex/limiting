<?php

declare(strict_types=1);

namespace Atannex\Limiting;

/**
 * The answer for a whole policy: allowed only if every limit allowed.
 */
final class Verdict
{
    /** @var list<Decision> */
    private array $decisions;

    /** @var (\Closure(Verdict): void)|null */
    private ?\Closure $releaser;

    /** @param  list<Decision>  $decisions */
    public function __construct(public readonly string $policy, array $decisions = [], ?\Closure $releaser = null)
    {
        $this->decisions = array_values($decisions);
        $this->releaser = $releaser;
    }

    public static function merge(string $policy, self ...$verdicts): self
    {
        $decisions = [];
        $releasers = [];

        foreach ($verdicts as $verdict) {
            array_push($decisions, ...$verdict->decisions());

            if ($verdict->releaser !== null) {
                $releasers[] = [$verdict, $verdict->releaser];
            }
        }

        return new self($policy, $decisions, $releasers === [] ? null : static function () use ($releasers): void {
            foreach ($releasers as [$verdict, $release]) {
                $release($verdict);
            }
        });
    }

    /** @return list<Decision> */
    public function decisions(): array
    {
        return $this->decisions;
    }

    public function allowed(): bool
    {
        foreach ($this->decisions as $decision) {
            if ($decision->denied()) {
                return false;
            }
        }

        return true;
    }

    public function denied(): bool
    {
        return ! $this->allowed();
    }

    public function jailed(): bool
    {
        return $this->has(Decision::JAILED);
    }

    public function forbidden(): bool
    {
        return $this->has(Decision::DENIED);
    }

    public function bypassed(): bool
    {
        return $this->decisions !== [] && $this->has(Decision::BYPASSED);
    }

    public function degraded(): bool
    {
        return $this->has(Decision::DEGRADED);
    }

    /** Some limit refused in shadow mode. */
    public function shadowed(): bool
    {
        foreach ($this->decisions as $decision) {
            if ($decision->shadowed) {
                return true;
            }
        }

        return false;
    }

    /** Seconds until every refusing limit would pass. */
    public function retryAfter(): float
    {
        $retry = 0.0;

        foreach ($this->decisions as $decision) {
            if ($decision->denied()) {
                $retry = max($retry, $decision->retryAfter);
            }
        }

        return $retry;
    }

    /** Seconds to wait before going ahead (leaky bucket shaping). */
    public function delay(): float
    {
        return array_reduce($this->decisions, static fn (float $carry, Decision $d): float => $d->allowed ? max($carry, $d->delay) : $carry, 0.0);
    }

    public function remaining(): int
    {
        $measured = array_filter($this->decisions, static fn (Decision $d): bool => $d->measured());

        return $measured === [] ? PHP_INT_MAX : min(array_map(static fn (Decision $d): int => $d->remaining, $measured));
    }

    /** The decision headers should report: a refusing one, else the one closest to its limit. */
    public function tightest(): ?Decision
    {
        $tightest = null;

        foreach ($this->decisions as $decision) {
            if ($decision->denied() && ($tightest === null || $tightest->allowed || $decision->retryAfter > $tightest->retryAfter)) {
                $tightest = $decision;
            } elseif ($decision->measured() && ($tightest === null || ($tightest->allowed && $decision->headroom() < $tightest->headroom()))) {
                $tightest = $decision;
            }
        }

        return $tightest;
    }

    /** @return list<Decision> refusing decisions */
    public function refusals(): array
    {
        return array_values(array_filter($this->decisions, static fn (Decision $d): bool => $d->denied()));
    }

    public function decision(string $limit): ?Decision
    {
        foreach ($this->decisions as $decision) {
            if ($decision->limit === $limit) {
                return $decision;
            }
        }

        return null;
    }

    /** @return list<string> concurrency leases this verdict holds */
    public function leases(): array
    {
        return array_values(array_filter(array_map(static fn (Decision $d): ?string => $d->allowed ? $d->lease : null, $this->decisions)));
    }

    /** Give back concurrency slots. Safe to call more than once. */
    public function release(): void
    {
        if ($this->releaser !== null) {
            $release = $this->releaser;
            $this->releaser = null;
            $release($this);
        }
    }

    private function has(string $reason): bool
    {
        foreach ($this->decisions as $decision) {
            if ($decision->reason === $reason) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'policy' => $this->policy,
            'allowed' => $this->allowed(),
            'retry_after' => round($this->retryAfter(), 3),
            'decisions' => array_map(static fn (Decision $d): array => $d->toArray(), $this->decisions),
        ];
    }
}
