<?php

declare(strict_types=1);

namespace Atannex\Limiting;

use Atannex\Limiting\Algorithms\Concurrency;
use Atannex\Limiting\Algorithms\FixedWindow;
use Atannex\Limiting\Algorithms\Gcra;
use Atannex\Limiting\Algorithms\LeakyBucket;
use Atannex\Limiting\Algorithms\Quota;
use Atannex\Limiting\Algorithms\SlidingLog;
use Atannex\Limiting\Algorithms\SlidingWindow;
use Atannex\Limiting\Algorithms\TokenBucket;
use Atannex\Limiting\Algorithms\Transition;
use Atannex\Limiting\Contracts\Algorithm;
use Atannex\Limiting\Contracts\Clock;
use Atannex\Limiting\Contracts\Store;
use Atannex\Limiting\Events\Limited;
use Atannex\Limiting\Events\StoreFailed;
use Atannex\Limiting\Events\WouldLimit;
use Atannex\Limiting\Exceptions\InvalidLimit;
use Atannex\Limiting\Jail\Jail;
use Atannex\Limiting\Lists\AccessList;
use Atannex\Limiting\Stats\Recorder;
use Atannex\Limiting\Subjects\SubjectResolver;
use Atannex\Limiting\Support\KeyGenerator;
use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Throwable;

/**
 * Evaluates limits. For each attempt it checks, in order:
 *
 *   1. the deny list (refused outright) and the allow list or bypasses (let through),
 *   2. the jail, for the sender's IP and user and for each limit's subject,
 *   3. each limit's cooldown block, then the limit itself, atomically in the store.
 *
 * When a later limit refuses, units spent on earlier limits are given back,
 * so a refused request costs nothing. Refusals trigger cooldowns, jail
 * strikes, events and stats; shadow-mode limits only report.
 */
final class Engine
{
    /** @var array<string, Algorithm|Closure(): Algorithm> */
    private array $algorithms;

    /** @var array<string, array{0: float, 1: int}> key => [refused until, for cost] */
    private array $memo = [];

    /** @var (Closure(Verdict, Context, bool): void)|null */
    private ?Closure $observer = null;

    /** @param  array<string, mixed>  $config  the limiting config */
    public function __construct(
        private readonly Store $store,
        private readonly Clock $clock,
        private readonly KeyGenerator $keys,
        private readonly SubjectResolver $subjects,
        private readonly Jail $jail,
        private readonly AccessList $lists,
        private readonly Recorder $stats,
        private readonly ?Dispatcher $events = null,
        private readonly array $config = [],
    ) {
        $timezone = (string) ($config['timezone'] ?? 'UTC');

        $this->algorithms = [
            Limit::FIXED_WINDOW => new FixedWindow,
            Limit::SLIDING_WINDOW => new SlidingWindow,
            Limit::SLIDING_LOG => new SlidingLog,
            Limit::TOKEN_BUCKET => new TokenBucket,
            Limit::LEAKY_BUCKET => new LeakyBucket,
            Limit::GCRA => new Gcra,
            Limit::QUOTA => new Quota($timezone),
            Limit::CONCURRENCY => new Concurrency,
        ];
    }

    /** @param  Algorithm|Closure(): Algorithm  $algorithm */
    public function extend(string $name, Algorithm|Closure $algorithm): void
    {
        $this->algorithms[$name] = $algorithm;
    }

    public function algorithm(string $name): Algorithm
    {
        $algorithm = $this->algorithms[$name] ?? throw new InvalidLimit("Unknown algorithm \"{$name}\". Use ".implode(', ', array_keys($this->algorithms)).'.');

        return $algorithm instanceof Closure ? $this->algorithms[$name] = $algorithm() : $algorithm;
    }

    /** @return list<string> */
    public function algorithms(): array
    {
        return array_keys($this->algorithms);
    }

    /** @param  Closure(Verdict, Context, bool): void  $observer */
    public function observe(?Closure $observer): void
    {
        $this->observer = $observer;
    }

    /**
     * Spend `$cost` units on every limit.
     *
     * @param  list<Limit>  $limits
     */
    public function attempt(string $policy, array $limits, Context $context, int $cost = 1): Verdict
    {
        return $this->run($policy, $limits, $context, $cost, true, true);
    }

    /**
     * Would `$cost` units pass? Spends nothing. With `$penalize`, a refusal
     * still counts toward cooldowns and jail (the middleware does this for
     * limits that only count failures).
     *
     * @param  list<Limit>  $limits
     */
    public function peek(string $policy, array $limits, Context $context, int $cost = 1, bool $penalize = false): Verdict
    {
        return $this->run($policy, $limits, $context, $cost, false, $penalize);
    }

    /** @param  list<Limit>  $limits */
    public function refund(string $policy, array $limits, Context $context, int $cost = 1): void
    {
        foreach ($limits as $limit) {
            if ($limit->algorithm === Limit::CONCURRENCY) {
                continue;
            }

            $key = $this->keys->counter($policy, $limit->name, $this->subjects->label($limit, $context));
            $this->give($limit, $key, $cost * $limit->costFor($context));
        }
    }

    /** Clear counters and cooldowns. @param  list<Limit>  $limits */
    public function reset(string $policy, array $limits, Context $context): void
    {
        foreach ($limits as $limit) {
            $label = $this->subjects->label($limit, $context);
            $key = $this->keys->counter($policy, $limit->name, $label);
            $this->store->forget($key);
            $this->store->forget($this->keys->block($policy, $limit->name, $label));
            $this->store->forget($this->keys->strikes($policy, $limit->name, $this->subjects->jailLabel($limit, $context)));
            unset($this->memo[$key]);
        }
    }

    /** Refuse everything for `$seconds`, e.g. when an upstream answers 429. @param  list<Limit>  $limits */
    public function block(string $policy, array $limits, Context $context, float $seconds): void
    {
        $until = $this->clock->now() + $seconds;

        foreach ($limits as $limit) {
            $this->store->put($this->keys->block($policy, $limit->name, $this->subjects->label($limit, $context)), ['until' => $until], $seconds);
        }
    }

    /**
     * The stored state of each limit, for inspection.
     *
     * @param  list<Limit>  $limits
     * @return list<array{limit: Limit, subject: string, algorithm: string, state: array<string, mixed>|null, blocked_until: float|null, decision: Decision}>
     */
    public function inspect(string $policy, array $limits, Context $context): array
    {
        $rows = [];
        $now = $this->clock->now();

        foreach ($limits as $limit) {
            $label = $this->subjects->label($limit, $context);
            $key = $this->keys->counter($policy, $limit->name, $label);
            $block = $this->store->get($this->keys->block($policy, $limit->name, $label));
            $algorithm = $limit->algorithmOr($this->defaultAlgorithm());

            $rows[] = [
                'limit' => $limit,
                'subject' => $label,
                'algorithm' => $algorithm,
                'state' => $state = $this->store->get($key),
                'blocked_until' => $block !== null && (float) $block['until'] > $now ? (float) $block['until'] : null,
                'decision' => $this->decision($policy, $limit, $label, $key, $this->algorithm($algorithm)->attempt($state, $limit, $now, 1)->outcome, $algorithm, 1, false),
            ];
        }

        return $rows;
    }

    public function clock(): Clock
    {
        return $this->clock;
    }

    public function store(): Store
    {
        return $this->store;
    }

    public function forgetMemo(): void
    {
        $this->memo = [];
    }

    /** @param  list<Limit>  $limits */
    private function run(string $policy, array $limits, Context $context, int $cost, bool $consume, bool $penalize): Verdict
    {
        $verdict = $this->evaluate($policy, $limits, $context, max(1, $cost), $consume, $penalize);

        // A pass over no limits (the middleware's up-front pass on a
        // failures-only policy) is only worth recording when it refused.
        if ($this->observer !== null && ($limits !== [] || $verdict->denied())) {
            ($this->observer)($verdict, $context, $consume);
        }

        return $verdict;
    }

    /** @param  list<Limit>  $limits */
    private function evaluate(string $policy, array $limits, Context $context, int $cost, bool $consume, bool $penalize): Verdict
    {
        if (! ($this->config['enabled'] ?? true)) {
            return new Verdict($policy);
        }

        if ($this->lists->denies($context)) {
            return new Verdict($policy, [$this->marker($policy, Decision::DENIED, false, $context)]);
        }

        if ($this->lists->allows($context)) {
            return new Verdict($policy, [$this->marker($policy, Decision::BYPASSED, true, $context)]);
        }

        $identities = $this->subjects->identities($context);

        if ($jailed = $this->jailed($policy, $identities)) {
            return new Verdict($policy, [$jailed]);
        }

        $now = $this->clock->now();
        $decisions = [];
        /** @var list<array{0: Limit, 1: string, 2: int, 3: ?string}> $spent */
        $spent = [];

        foreach ($limits as $limit) {
            $label = $this->subjects->label($limit, $context);

            if (! in_array($label, $identities, true) && ($jailed = $this->jailed($policy, [$label]))) {
                $decisions[] = $jailed;
                break;
            }

            $units = $cost * $limit->costFor($context);
            $decision = $this->decide($policy, $limit, $label, $context, $units, $now, $consume, $penalize);
            $decisions[] = $decision;

            if ($consume && $decision->allowed && ! $decision->shadowed && $decision->reason === Decision::LIMIT) {
                $spent[] = [$limit, $decision->key, $units, $decision->lease];
            }

            if ($decision->denied()) {
                break;
            }
        }

        $refused = false;

        foreach ($decisions as $decision) {
            $refused = $refused || $decision->denied();
        }

        if ($refused) {
            foreach ($spent as [$limit, $key, $units, $lease]) {
                $this->give($limit, $key, $units, $lease);
            }

            return new Verdict($policy, $decisions);
        }

        $leases = array_values(array_filter($spent, static fn (array $entry): bool => $entry[3] !== null));

        return new Verdict($policy, $decisions, $leases === [] ? null : function () use ($leases): void {
            foreach ($leases as [$limit, $key, $units, $lease]) {
                $this->give($limit, $key, $units, $lease);
            }
        });
    }

    private function decide(string $policy, Limit $limit, string $label, Context $context, int $units, float $now, bool $consume, bool $penalize): Decision
    {
        $algorithmName = $limit->algorithmOr($this->defaultAlgorithm());
        $algorithm = $this->algorithm($algorithmName);
        $key = $this->keys->counter($policy, $limit->name, $label);

        try {
            if ($limit->blockFor !== null || ($this->config['check_blocks'] ?? true)) {
                $block = $this->store->get($this->keys->block($policy, $limit->name, $label));

                if ($block !== null && (float) $block['until'] > $now) {
                    $decision = new Decision($policy, $limit->name, false, $limit->max, 0, $limit->window, (float) $block['until'] - $now, (float) $block['until'] - $now, $label, $key, Decision::BLOCKED, cost: $units, algorithm: $algorithmName);

                    return $this->shadowed($limit) ? $this->report($decision, $limit, $context, false) : $this->refused($decision, $limit, $context, $penalize, false);
                }
            }

            if ($consume && isset($this->memo[$key]) && $this->memo[$key][0] > $now && $this->memo[$key][1] <= $units && ! $this->shadowed($limit)) {
                $decision = new Decision($policy, $limit->name, false, $limit->max, 0, $limit->window, $this->memo[$key][0] - $now, $this->memo[$key][0] - $now, $label, $key, cost: $units, algorithm: $algorithmName);

                return $this->refused($decision, $limit, $context, $penalize, false);
            }

            $transition = $consume
                ? $this->store->update($key, static fn (?array $state): array => $algorithm->attempt($state, $limit, $now, $units)->forStore())
                : $algorithm->attempt($this->store->get($key), $limit, $now, $units);
        } catch (Throwable $e) {
            return $this->degraded($policy, $limit, $label, $key, $e, $algorithmName);
        }

        /** @var Transition $transition */
        $decision = $this->decision($policy, $limit, $label, $key, $transition->outcome, $algorithmName, $units, $this->shadowed($limit));

        if ($transition->outcome->allowed) {
            return $decision;
        }

        return $decision->shadowed ? $this->report($decision, $limit, $context, true) : $this->refused($decision, $limit, $context, $penalize, true);
    }

    private function decision(string $policy, Limit $limit, string $label, string $key, Algorithms\Outcome $outcome, string $algorithm, int $units, bool $shadow): Decision
    {
        return new Decision(
            policy: $policy,
            limit: (string) $limit->name,
            allowed: $outcome->allowed || $shadow,
            max: $limit->max,
            remaining: $outcome->remaining,
            window: $limit->window,
            resetAfter: $outcome->resetAfter,
            retryAfter: $outcome->allowed ? 0.0 : $outcome->retryAfter,
            subject: $label,
            key: $key,
            delay: $outcome->delay,
            lease: $outcome->lease,
            shadowed: ! $outcome->allowed && $shadow,
            cost: $units,
            algorithm: $algorithm,
        );
    }

    /** A refusal: cooldown, memo, jail strike, event, stats. */
    private function refused(Decision $decision, Limit $limit, Context $context, bool $penalize, bool $fresh): Decision
    {
        $now = $this->clock->now();

        if ($penalize) {
            try {
                if ($fresh && $limit->blockFor !== null) {
                    $until = $now + max($limit->blockFor, $decision->retryAfter);
                    $this->store->put($this->keys->block($decision->policy, $limit->name, $decision->subject), ['until' => $until], $until - $now);
                    $decision = $this->with($decision, retryAfter: $until - $now, reason: Decision::BLOCKED);
                }

                if ($limit->jail !== null && $this->jail->enabled()) {
                    $jailLabel = $this->subjects->jailLabel($limit, $context);
                    $record = $this->jail->strike($jailLabel, $limit->jail, $this->keys->strikes($decision->policy, $limit->name, $jailLabel), $decision->policy);

                    if ($record !== null) {
                        $decision = $this->with($decision, retryAfter: (float) $record['until'] - $now, reason: Decision::JAILED);
                    }
                }
            } catch (Throwable $e) {
                $this->events?->dispatch(new StoreFailed($e, $decision->policy, $decision->limit, false));
            }
        }

        if (($this->config['memo'] ?? true) && $decision->retryAfter > 0 && $limit->algorithm !== Limit::CONCURRENCY) {
            if (count($this->memo) > 1000) {
                $this->memo = array_filter($this->memo, static fn (array $entry): bool => $entry[0] > $now);
            }

            $this->memo[$decision->key] = [$now + $decision->retryAfter, $decision->cost];
        }

        $this->events?->dispatch(new Limited($decision, $context));
        $this->recordStats($decision);

        return $decision;
    }

    /** Shadow mode: report, let through. */
    private function report(Decision $decision, Limit $limit, Context $context, bool $fresh): Decision
    {
        if (! $decision->shadowed) {
            $decision = $this->with($decision, allowed: true, shadowed: true);
        }

        $this->events?->dispatch(new WouldLimit($decision, $context));
        $this->recordStats($decision);

        return $decision;
    }

    private function degraded(string $policy, Limit $limit, string $label, string $key, Throwable $e, string $algorithm): Decision
    {
        $closed = ($limit->fail ?? $this->config['fail'] ?? 'open') === 'closed';
        $retry = (float) ($this->config['fail_closed_retry'] ?? 30);

        $this->events?->dispatch(new StoreFailed($e, $policy, (string) $limit->name, $closed));

        return new Decision($policy, (string) $limit->name, ! $closed, $limit->max, $closed ? 0 : $limit->max, $limit->window, 0.0, $closed ? $retry : 0.0, $label, $key, Decision::DEGRADED, algorithm: $algorithm);
    }

    /** @param  list<string>  $labels */
    private function jailed(string $policy, array $labels): ?Decision
    {
        if (! $this->jail->enabled()) {
            return null;
        }

        foreach ($labels as $label) {
            try {
                $record = $this->jail->find($label);
            } catch (Throwable $e) {
                $this->events?->dispatch(new StoreFailed($e, $policy, 'jail', false));

                return null;
            }

            if ($record !== null) {
                $retry = (float) $record['until'] - $this->clock->now();

                return new Decision($policy, 'jail', false, 0, 0, $retry, $retry, $retry, $label, reason: Decision::JAILED);
            }
        }

        return null;
    }

    private function marker(string $policy, string $reason, bool $allowed, Context $context): Decision
    {
        $subject = $this->subjects->identities($context)[0] ?? 'global';

        return new Decision($policy, $reason, $allowed, PHP_INT_MAX, PHP_INT_MAX, 0, 0, 0, $subject, reason: $reason);
    }

    private function give(Limit $limit, string $key, int $units, ?string $lease = null): void
    {
        $algorithm = $this->algorithm($limit->algorithmOr($this->defaultAlgorithm()));
        $now = $this->clock->now();
        unset($this->memo[$key]);

        try {
            $this->store->update($key, static function (?array $state) use ($algorithm, $limit, $now, $units, $lease): array {
                $transition = $algorithm->refund($state, $limit, $now, $units, $lease);

                return $transition === null ? [$state, 0.0, null, false] : $transition->forStore();
            });
        } catch (Throwable $e) {
            $this->events?->dispatch(new StoreFailed($e, 'refund', (string) $limit->name, false));
        }
    }

    private function recordStats(Decision $decision): void
    {
        try {
            $this->stats->record($decision);
        } catch (Throwable) {
            // Stats are best effort.
        }
    }

    private function with(Decision $decision, ?float $retryAfter = null, ?string $reason = null, ?bool $allowed = null, ?bool $shadowed = null): Decision
    {
        return new Decision(
            $decision->policy, $decision->limit, $allowed ?? $decision->allowed, $decision->max, $decision->remaining, $decision->window,
            max($decision->resetAfter, $retryAfter ?? 0.0), $retryAfter ?? $decision->retryAfter, $decision->subject, $decision->key,
            $reason ?? $decision->reason, $decision->delay, $decision->lease, $shadowed ?? $decision->shadowed, $decision->cost, $decision->algorithm,
        );
    }

    private function shadowed(Limit $limit): bool
    {
        return $limit->shadow || (bool) ($this->config['dry_run'] ?? false);
    }

    private function defaultAlgorithm(): string
    {
        return (string) ($this->config['default_algorithm'] ?? Limit::SLIDING_WINDOW);
    }
}
