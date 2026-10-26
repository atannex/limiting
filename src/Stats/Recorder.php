<?php

declare(strict_types=1);

namespace Atannex\Limiting\Stats;

use Atannex\Limiting\Contracts\Clock;
use Atannex\Limiting\Contracts\Store;
use Atannex\Limiting\Decision;
use Atannex\Limiting\Support\KeyGenerator;

/**
 * Hourly counts of refusals per policy and limit, and the subjects refused
 * most, for `limiting:stats`. Only refusals are written, so normal traffic
 * costs nothing.
 */
final class Recorder
{
    /** @param  array<string, mixed>  $config  the limiting.stats config */
    public function __construct(
        private readonly Store $store,
        private readonly KeyGenerator $keys,
        private readonly Clock $clock,
        private readonly array $config = [],
    ) {}

    public function enabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? true);
    }

    public function record(Decision $decision): void
    {
        if (! $this->enabled()) {
            return;
        }

        $retention = (float) ($this->config['retention'] ?? 48) * 3600;
        $top = (int) ($this->config['top'] ?? 20);
        $bucket = $decision->shadowed ? 'shadow' : $decision->reason;
        $name = "{$decision->policy}:{$decision->limit}";
        $subject = $decision->subject;

        $this->store->update($this->keys->stats(gmdate('YmdH', (int) $this->clock->now())), static function (?array $state) use ($retention, $top, $bucket, $name, $subject): array {
            $state ??= ['limits' => [], 'subjects' => []];
            $state['limits'][$name][$bucket] = ($state['limits'][$name][$bucket] ?? 0) + 1;
            $state['subjects'][$subject] = ($state['subjects'][$subject] ?? 0) + 1;

            if (count($state['subjects']) > $top * 5) {
                arsort($state['subjects']);
                $state['subjects'] = array_slice($state['subjects'], 0, $top * 2, true);
            }

            return [$state, $retention, null, true];
        });
    }

    /**
     * @return array{limits: array<string, array<string, int>>, subjects: array<string, int>}
     */
    public function summary(int $hours = 24): array
    {
        $now = (int) $this->clock->now();
        $limits = [];
        $subjects = [];

        for ($i = 0; $i < $hours; $i++) {
            $state = $this->store->get($this->keys->stats(gmdate('YmdH', $now - $i * 3600)));

            foreach ($state['limits'] ?? [] as $name => $buckets) {
                foreach ($buckets as $bucket => $count) {
                    $limits[$name][$bucket] = ($limits[$name][$bucket] ?? 0) + $count;
                }
            }

            foreach ($state['subjects'] ?? [] as $subject => $count) {
                $subjects[$subject] = ($subjects[$subject] ?? 0) + $count;
            }
        }

        arsort($subjects);
        uasort($limits, static fn (array $a, array $b): int => array_sum($b) <=> array_sum($a));

        return ['limits' => $limits, 'subjects' => array_slice($subjects, 0, (int) ($this->config['top'] ?? 20), true)];
    }
}
