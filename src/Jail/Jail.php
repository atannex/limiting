<?php

declare(strict_types=1);

namespace Atannex\Limiting\Jail;

use Atannex\Limiting\Contracts\Clock;
use Atannex\Limiting\Contracts\Store;
use Atannex\Limiting\Events\Jailed;
use Atannex\Limiting\Events\Released;
use Atannex\Limiting\Support\KeyGenerator;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Jailed subjects are refused by every policy, and by the shield middleware,
 * until their sentence ends. Limits with ->jail() send repeat offenders here;
 * each new sentence within `memory` lasts `escalate` times longer.
 */
final class Jail
{
    /** @param  array<string, mixed>  $config  the limiting.jail config */
    public function __construct(
        private readonly Store $store,
        private readonly KeyGenerator $keys,
        private readonly Clock $clock,
        private readonly ?Dispatcher $events = null,
        private readonly array $config = [],
    ) {}

    public function enabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? true);
    }

    /** @return array{subject: string, until: float, since: float, reason: string, policy: ?string, sentence: int}|null */
    public function find(string $subject): ?array
    {
        $record = $this->store->get($this->keys->jail($subject));

        return $record !== null && (float) $record['until'] > $this->clock->now() ? $record : null;
    }

    public function isJailed(string $subject): bool
    {
        return $this->find($subject) !== null;
    }

    /** Seconds left, 0 when free. */
    public function remaining(string $subject): float
    {
        $record = $this->find($subject);

        return $record === null ? 0.0 : max(0.0, (float) $record['until'] - $this->clock->now());
    }

    /** Jail for a fixed time, whatever the history. */
    public function lock(string $subject, float $seconds, string $reason = 'manual', ?string $policy = null): array
    {
        return $this->imprison($subject, $seconds, $reason, $policy, $this->remember($subject));
    }

    /**
     * Count one refusal against `$subject`; jail it once it reaches
     * `after` refusals within `within`.
     *
     * @param  array{after: int, within: float, for: float, escalate: float, max: float}  $rule
     * @return array<string, mixed>|null the jail record when this strike jailed it
     */
    public function strike(string $subject, array $rule, string $strikesKey, string $policy): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $now = $this->clock->now();

        $count = $this->store->update($strikesKey, static function (?array $state) use ($rule, $now): array {
            $fresh = $state === null || (float) $state['since'] + $rule['within'] <= $now;
            $count = $fresh ? 1 : (int) $state['n'] + 1;

            return [['n' => $count, 'since' => $fresh ? $now : (float) $state['since']], $rule['within'], $count, true];
        });

        if ($count < $rule['after']) {
            return null;
        }

        $this->store->forget($strikesKey);
        $previous = $this->history($subject);
        $seconds = min($rule['max'], $rule['for'] * ($rule['escalate'] ** $previous));

        return $this->imprison($subject, $seconds, 'repeated limit violations', $policy, $this->remember($subject));
    }

    public function release(string $subject): bool
    {
        $jailed = $this->find($subject) !== null;
        $this->store->forget($this->keys->jail($subject));
        $this->index(static function (array $entries) use ($subject): array {
            unset($entries[$subject]);

            return $entries;
        });

        if ($jailed) {
            $this->events?->dispatch(new Released($subject));
        }

        return $jailed;
    }

    /** Forget the sentence history too, so the next sentence starts from the base length. */
    public function pardon(string $subject): bool
    {
        $this->store->forget($this->keys->record($subject));

        return $this->release($subject);
    }

    /** @return list<array{subject: string, until: float, since: float, reason: string, policy: ?string, sentence: int}> */
    public function all(): array
    {
        $now = $this->clock->now();
        $entries = $this->store->get($this->keys->jailIndex()) ?? [];
        $records = [];

        foreach ($entries as $entry) {
            if ((float) $entry['until'] > $now) {
                $records[] = $entry;
            }
        }

        usort($records, static fn (array $a, array $b): int => $b['until'] <=> $a['until']);

        return $records;
    }

    private function imprison(string $subject, float $seconds, string $reason, ?string $policy, int $sentence): array
    {
        $now = $this->clock->now();
        $existing = $this->find($subject);
        $until = max($now + $seconds, (float) ($existing['until'] ?? 0));

        $record = [
            'subject' => $subject,
            'until' => $until,
            'since' => $now,
            'reason' => $reason,
            'policy' => $policy,
            'sentence' => $sentence,
        ];

        $this->store->put($this->keys->jail($subject), $record, $until - $now);
        $this->index(static function (array $entries) use ($subject, $record): array {
            $entries[$subject] = $record;

            return $entries;
        });

        $this->events?->dispatch(new Jailed($subject, $until, $until - $now, $reason, $policy, $sentence));

        return $record;
    }

    private function history(string $subject): int
    {
        return (int) ($this->store->get($this->keys->record($subject))['n'] ?? 0);
    }

    /** Count a sentence; returns how many there have been, this one included. */
    private function remember(string $subject): int
    {
        $memory = (float) ($this->config['memory'] ?? 2_592_000);

        return $this->store->update($this->keys->record($subject), static function (?array $state) use ($memory): array {
            $n = (int) ($state['n'] ?? 0) + 1;

            return [['n' => $n], $memory, $n, true];
        });
    }

    /** @param  \Closure(array<string, array<string, mixed>>): array<string, array<string, mixed>>  $change */
    private function index(\Closure $change): void
    {
        $now = $this->clock->now();
        $max = (int) ($this->config['max_index'] ?? 1000);

        $this->store->update($this->keys->jailIndex(), static function (?array $entries) use ($change, $now, $max): array {
            $entries = array_filter($entries ?? [], static fn (array $entry): bool => (float) $entry['until'] > $now);
            $entries = $change($entries);

            if (count($entries) > $max) {
                uasort($entries, static fn (array $a, array $b): int => $b['until'] <=> $a['until']);
                $entries = array_slice($entries, 0, $max, true);
            }

            $ttl = $entries === [] ? 1.0 : max(array_map(static fn (array $entry): float => (float) $entry['until'], $entries)) - $now + 1;

            return [$entries === [] ? null : $entries, $ttl, null, true];
        });
    }
}
