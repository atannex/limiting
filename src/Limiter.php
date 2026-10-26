<?php

declare(strict_types=1);

namespace Atannex\Limiting;

use Atannex\Limiting\Exceptions\RateLimitExceeded;
use Atannex\Limiting\Support\Duration;
use Closure;
use DateInterval;

/**
 * A policy bound to a subject:
 *
 *     $limiter = Limiting::for('login', $request);
 *     $limiter = Limiting::for('mtn-api', 'tenant:'.$tenant->id);
 *
 *     $limiter->attempt()->allowed();     // spend one unit
 *     $limiter->tooManyAttempts();        // would one more pass?
 *     $limiter->run(fn () => $send());    // run if allowed, else false
 *     $limiter->wait('10 seconds');       // sleep until allowed
 */
final class Limiter
{
    public function __construct(
        private readonly Engine $engine,
        public readonly Policy $policy,
        public readonly Context $context,
    ) {}

    /** Spend `$cost` units; check ->allowed() on the result. */
    public function attempt(int $cost = 1): Verdict
    {
        return $this->engine->attempt($this->policy->name, $this->limits(), $this->context, $cost);
    }

    /** Alias of attempt(), for the "hit after a failure" style. */
    public function hit(int $cost = 1): Verdict
    {
        return $this->attempt($cost);
    }

    /** Would `$cost` units pass? Spends nothing. */
    public function check(int $cost = 1): Verdict
    {
        return $this->engine->peek($this->policy->name, $this->limits(), $this->context, $cost);
    }

    public function tooManyAttempts(int $cost = 1): bool
    {
        return $this->check($cost)->denied();
    }

    /** Units left on the tightest limit. */
    public function remaining(): int
    {
        $values = [];

        // A check reports what would be left after one more unit; add it back.
        foreach ($this->check()->decisions() as $decision) {
            if ($decision->measured()) {
                $values[] = $decision->allowed && ! $decision->shadowed ? $decision->remaining + $decision->cost : $decision->remaining;
            }
        }

        return $values === [] ? PHP_INT_MAX : min($values);
    }

    /** Whole seconds until one more unit would pass. */
    public function availableIn(int $cost = 1): int
    {
        return (int) ceil($this->check($cost)->retryAfter());
    }

    /**
     * Run `$callback` if `$cost` units are available, holding any concurrency
     * slots until it returns. Returns its result, or false when refused.
     */
    public function run(Closure $callback, int $cost = 1): mixed
    {
        $verdict = $this->attempt($cost);

        if ($verdict->denied()) {
            return false;
        }

        try {
            return $callback($verdict);
        } finally {
            $verdict->release();
        }
    }

    /**
     * Run `$callback` or throw RateLimitExceeded. The thrown exception
     * renders as a 429 with Retry-After.
     */
    public function orFail(?Closure $callback = null, int $cost = 1): mixed
    {
        $verdict = $this->attempt($cost);

        if ($verdict->denied()) {
            throw new RateLimitExceeded($verdict, ['Retry-After' => (string) max(1, (int) ceil($verdict->retryAfter()))]);
        }

        if ($callback === null) {
            return $verdict;
        }

        try {
            return $callback($verdict);
        } finally {
            $verdict->release();
        }
    }

    /**
     * Wait (sleeping) until `$cost` units pass, for up to `$timeout`. For
     * queue workers and outgoing API calls, not for web requests.
     *
     * @throws RateLimitExceeded when it would take longer than `$timeout`
     */
    public function wait(int|float|string|DateInterval $timeout = 10, int $cost = 1): Verdict
    {
        $clock = $this->engine->clock();
        $deadline = $clock->now() + Duration::seconds($timeout);

        while (true) {
            $verdict = $this->attempt($cost);

            if ($verdict->allowed()) {
                if (($delay = $verdict->delay()) > 0) {
                    $clock->sleep($delay);
                }

                return $verdict;
            }

            $retry = max(0.001, $verdict->retryAfter());

            if ($verdict->forbidden() || $clock->now() + $retry > $deadline) {
                throw new RateLimitExceeded($verdict, ['Retry-After' => (string) max(1, (int) ceil($retry))]);
            }

            $clock->sleep($retry + random_int(0, 20) / 1000);
        }
    }

    /** Give units back, e.g. when the work turned out not to count. */
    public function refund(int $cost = 1): void
    {
        $this->engine->refund($this->policy->name, $this->limits(), $this->context, $cost);
    }

    /** Clear the counters, cooldowns and strikes for this subject. */
    public function reset(): void
    {
        $this->engine->reset($this->policy->name, $this->limits(), $this->context);
    }

    /** Alias of reset(), Laravel's name. */
    public function clear(): void
    {
        $this->reset();
    }

    /** Refuse this subject for `$duration` on this policy. */
    public function block(int|float|string|DateInterval $duration): void
    {
        $this->engine->block($this->policy->name, $this->limits(), $this->context, Duration::seconds($duration));
    }

    /** @return list<array<string, mixed>> */
    public function inspect(): array
    {
        return $this->engine->inspect($this->policy->name, $this->limits(), $this->context);
    }

    /** @return list<Limit> */
    public function limits(): array
    {
        return $this->policy->limits($this->context);
    }
}
