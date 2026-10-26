<?php

declare(strict_types=1);

namespace Atannex\Limiting\Algorithms;

use Atannex\Limiting\Contracts\Algorithm;
use Atannex\Limiting\Limit;
use Carbon\CarbonImmutable;

/**
 * A calendar quota: so many per day, week, month or year, resetting at
 * midnight (Monday, the 1st, 1 January) in the limit's time zone.
 */
final class Quota implements Algorithm
{
    public function __construct(private readonly string $timezone = 'UTC') {}

    public function attempt(?array $state, Limit $limit, float $now, int $cost): Transition
    {
        [$start, $end] = $this->period($limit, $now);
        $count = $state !== null && (int) $state['s'] === $start ? (int) $state['c'] : 0;
        $reset = $end - $now;

        if ($count + $cost > $limit->max) {
            return Transition::deny(new Outcome(false, max(0, $limit->max - $count), $reset, $reset), $state, $reset);
        }

        $count += $cost;

        return Transition::allow(new Outcome(true, $limit->max - $count, $reset), ['s' => $start, 'c' => $count], $reset + 60);
    }

    public function refund(?array $state, Limit $limit, float $now, int $cost, ?string $lease = null): ?Transition
    {
        [$start, $end] = $this->period($limit, $now);

        if ($state === null || (int) $state['s'] !== $start) {
            return null;
        }

        $count = max(0, (int) $state['c'] - $cost);

        return Transition::allow(new Outcome(true, $limit->max - $count, $end - $now), ['s' => $start, 'c' => $count], $end - $now + 60);
    }

    /** @return array{0: int, 1: int} */
    public function period(Limit $limit, float $now): array
    {
        $at = CarbonImmutable::createFromTimestamp((int) floor($now), $limit->timezone ?? $this->timezone);

        $start = match ($limit->period) {
            'day' => $at->startOfDay(),
            'week' => $at->startOfWeek(CarbonImmutable::MONDAY),
            'year' => $at->startOfYear(),
            default => $at->startOfMonth(),
        };

        $end = match ($limit->period) {
            'day' => $start->addDay(),
            'week' => $start->addWeek(),
            'year' => $start->addYear(),
            default => $start->addMonthNoOverflow(),
        };

        return [$start->getTimestamp(), $end->getTimestamp()];
    }
}
