<?php

declare(strict_types=1);

namespace Atannex\Limiting\Tests\Unit;

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
use Atannex\Limiting\Limit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AlgorithmsTest extends TestCase
{
    private const T0 = 1_700_000_020.0; // 40 s into a minute

    /** Runs attempts, threading the state like a store would. */
    private function run_(Algorithm $algorithm, Limit $limit, float $at, int $times, ?array &$state, int $cost = 1): array
    {
        $outcomes = [];

        for ($i = 0; $i < $times; $i++) {
            $transition = $algorithm->attempt($state, $limit, $at, $cost);

            if ($transition->write) {
                $state = $transition->state;
            }

            $outcomes[] = $transition->outcome;
        }

        return $outcomes;
    }

    private function allowed(array $outcomes): int
    {
        return count(array_filter($outcomes, fn ($o) => $o->allowed));
    }

    public static function counting(): array
    {
        return [
            'fixed window' => [new FixedWindow],
            'sliding window' => [new SlidingWindow],
            'sliding log' => [new SlidingLog],
            'token bucket' => [new TokenBucket],
            'gcra' => [new Gcra],
        ];
    }

    #[DataProvider('counting')]
    public function test_it_allows_exactly_the_limit_then_refuses(Algorithm $algorithm): void
    {
        $state = null;
        $outcomes = $this->run_($algorithm, Limit::perMinute(10), self::T0, 15, $state);

        $this->assertSame(10, $this->allowed($outcomes));
        $this->assertSame(9, $outcomes[0]->remaining);
        $this->assertSame(0, $outcomes[9]->remaining);
        $this->assertFalse($outcomes[10]->allowed);
        $this->assertGreaterThan(0, $outcomes[10]->retryAfter);
    }

    #[DataProvider('counting')]
    public function test_a_refusal_leaves_the_state_alone(Algorithm $algorithm): void
    {
        $state = null;
        $this->run_($algorithm, Limit::perMinute(2), self::T0, 2, $state);
        $transition = $algorithm->attempt($state, Limit::perMinute(2), self::T0, 1);

        $this->assertFalse($transition->outcome->allowed);
        $this->assertFalse($transition->write);
    }

    #[DataProvider('counting')]
    public function test_costs_spend_several_units(Algorithm $algorithm): void
    {
        $state = null;
        $outcomes = $this->run_($algorithm, Limit::perMinute(10), self::T0, 4, $state, 3);

        $this->assertSame(3, $this->allowed($outcomes));
        $this->assertSame(1, $outcomes[2]->remaining);
    }

    #[DataProvider('counting')]
    public function test_the_retry_time_is_when_the_attempt_would_pass(Algorithm $algorithm): void
    {
        $limit = Limit::perMinute(10);
        $state = null;
        $this->run_($algorithm, $limit, self::T0, 10, $state);
        $retry = $algorithm->attempt($state, $limit, self::T0, 1)->outcome->retryAfter;

        $this->assertFalse($algorithm->attempt($state, $limit, self::T0 + $retry - 0.01, 1)->outcome->allowed, 'passes before retryAfter');
        $this->assertTrue($algorithm->attempt($state, $limit, self::T0 + $retry + 0.001, 1)->outcome->allowed, 'still refused after retryAfter');
    }

    #[DataProvider('counting')]
    public function test_refunds_give_units_back(Algorithm $algorithm): void
    {
        $limit = Limit::perMinute(3);
        $state = null;
        $this->run_($algorithm, $limit, self::T0, 3, $state);
        $state = $algorithm->refund($state, $limit, self::T0, 1)->state;

        $this->assertTrue($algorithm->attempt($state, $limit, self::T0, 1)->outcome->allowed);
    }

    public function test_fixed_window_resets_on_the_boundary(): void
    {
        $algorithm = new FixedWindow;
        $limit = Limit::perMinute(5);
        $state = null;
        $this->run_($algorithm, $limit, self::T0, 5, $state);

        $refused = $algorithm->attempt($state, $limit, self::T0, 1)->outcome;
        $this->assertEqualsWithDelta(20.0, $refused->retryAfter, 0.001);
        $this->assertTrue($algorithm->attempt($state, $limit, self::T0 + 20, 1)->outcome->allowed);
    }

    public function test_sliding_window_has_no_double_burst_at_the_window_edge(): void
    {
        $algorithm = new SlidingWindow;
        $limit = Limit::perMinute(10);
        $state = null;

        // Ten at 59 s into a minute, then try again at 61 s: a fixed window
        // would allow ten more; the sliding window allows almost none.
        $this->run_($algorithm, $limit, 1_700_000_039.0, 10, $state);
        $after = $this->run_($algorithm, $limit, 1_700_000_041.0, 10, $state);

        $this->assertLessThanOrEqual(1, $this->allowed($after));

        // A full window later everything is back.
        $later = $this->run_($algorithm, $limit, 1_700_000_039.0 + 121, 10, $state);
        $this->assertSame(10, $this->allowed($later));
    }

    public function test_sliding_window_weights_the_previous_window(): void
    {
        $algorithm = new SlidingWindow;
        $limit = Limit::perMinute(10);

        // Ten hits late in the window starting 1_699_999_980; 30 s into the
        // next window half of them are weighted in.
        $state = null;
        $this->run_($algorithm, $limit, 1_700_000_039.0, 10, $state);
        $this->assertSame(5, $this->allowed($this->run_($algorithm, $limit, 1_700_000_070.0, 10, $state)));

        // Ten hits early in that window are all more than a minute old by
        // then, so they no longer count at all.
        $state = null;
        $this->run_($algorithm, $limit, 1_699_999_981.0, 10, $state);
        $this->assertSame(10, $this->allowed($this->run_($algorithm, $limit, 1_700_000_070.0, 10, $state)));
    }

    public function test_sliding_window_is_exact_for_one_a_minute(): void
    {
        $algorithm = new SlidingWindow;
        $limit = Limit::perMinute(1);
        $state = null;
        $this->run_($algorithm, $limit, self::T0, 1, $state);

        $this->assertEqualsWithDelta(60.0, $algorithm->attempt($state, $limit, self::T0, 1)->outcome->retryAfter, 0.01);
        $this->assertFalse($algorithm->attempt($state, $limit, self::T0 + 59.9, 1)->outcome->allowed);
        $this->assertTrue($algorithm->attempt($state, $limit, self::T0 + 60.01, 1)->outcome->allowed);
    }

    public function test_sliding_log_frees_units_as_hits_age_out(): void
    {
        $algorithm = new SlidingLog;
        $limit = Limit::perMinute(3);
        $state = null;
        $this->run_($algorithm, $limit, self::T0, 1, $state);
        $this->run_($algorithm, $limit, self::T0 + 10, 2, $state);

        $refused = $algorithm->attempt($state, $limit, self::T0 + 20, 1)->outcome;
        $this->assertFalse($refused->allowed);
        $this->assertEqualsWithDelta(40.0, $refused->retryAfter, 0.001);
        $this->assertTrue($algorithm->attempt($state, $limit, self::T0 + 60.01, 1)->outcome->allowed);
        $this->assertFalse($algorithm->attempt($state, $limit, self::T0 + 60.01, 2)->outcome->allowed);
    }

    public function test_token_bucket_bursts_then_refills_at_the_rate(): void
    {
        $algorithm = new TokenBucket;
        $limit = Limit::perMinute(60)->tokenBucket(burst: 5); // one a second, five at once
        $state = null;

        $this->assertSame(5, $this->allowed($this->run_($algorithm, $limit, self::T0, 10, $state)));
        $this->assertSame(2, $this->allowed($this->run_($algorithm, $limit, self::T0 + 2, 10, $state)));

        $refused = $algorithm->attempt($state, $limit, self::T0 + 2.25, 1)->outcome;
        $this->assertEqualsWithDelta(0.75, $refused->retryAfter, 0.001);
    }

    public function test_gcra_spaces_requests_after_the_burst(): void
    {
        $algorithm = new Gcra;
        $limit = Limit::perMinute(60)->gcra(burst: 3);
        $state = null;

        $this->assertSame(3, $this->allowed($this->run_($algorithm, $limit, self::T0, 10, $state)));
        $refused = $algorithm->attempt($state, $limit, self::T0, 1)->outcome;
        $this->assertEqualsWithDelta(1.0, $refused->retryAfter, 0.001);
        $this->assertSame(1, $this->allowed($this->run_($algorithm, $limit, self::T0 + 1, 5, $state)));
    }

    public function test_leaky_bucket_queues_with_growing_delays(): void
    {
        $algorithm = new LeakyBucket;
        $limit = Limit::perSecond(10)->leakyBucket(maxDelay: 0.5); // one per 100 ms, wait at most 500 ms
        $state = null;
        $outcomes = $this->run_($algorithm, $limit, self::T0, 10, $state);

        $this->assertSame(6, $this->allowed($outcomes));
        $this->assertEqualsWithDelta(0.0, $outcomes[0]->delay, 1e-6);
        $this->assertEqualsWithDelta(0.1, $outcomes[1]->delay, 1e-6);
        $this->assertEqualsWithDelta(0.5, $outcomes[5]->delay, 1e-6);
        $this->assertFalse($outcomes[6]->allowed);
        $this->assertEqualsWithDelta(0.1, $outcomes[6]->retryAfter, 1e-6);
    }

    /** A request that read the clock before waiting for the store lock reaches the state after newer hits. */
    #[DataProvider('counting')]
    public function test_a_stale_clock_reading_never_rolls_the_state_back(Algorithm $algorithm): void
    {
        $limit = Limit::perMinute(3);
        $state = null;
        $boundary = self::T0 + 20; // the next minute starts

        $this->assertSame(3, $this->allowed($this->run_($algorithm, $limit, $boundary + 0.5, 3, $state)));
        $this->assertSame(0, $this->allowed($this->run_($algorithm, $limit, $boundary - 0.01, 1, $state)));
        $this->assertSame(0, $this->allowed($this->run_($algorithm, $limit, $boundary + 0.5, 1, $state)));
    }

    public function test_a_stale_clock_reading_never_rolls_a_quota_back(): void
    {
        $algorithm = new Quota;
        $limit = Limit::monthly(2);
        $state = null;
        $april = (float) gmmktime(0, 0, 0, 4, 1, 2026);

        $this->assertSame(2, $this->allowed($this->run_($algorithm, $limit, $april + 1, 2, $state)));
        $this->assertSame(0, $this->allowed($this->run_($algorithm, $limit, $april - 0.5, 1, $state)));
    }

    public function test_quota_resets_at_the_calendar_boundary_in_its_time_zone(): void
    {
        $algorithm = new Quota;
        $limit = Limit::monthly(2, 'Africa/Douala'); // UTC+1
        $state = null;

        // 31 March 2026, 22:30 UTC = 23:30 in Douala: still March.
        $march = (float) gmmktime(22, 30, 0, 3, 31, 2026);
        $this->assertSame(2, $this->allowed($this->run_($algorithm, $limit, $march, 3, $state)));

        $refused = $algorithm->attempt($state, $limit, $march, 1)->outcome;
        $this->assertEqualsWithDelta(1800.0, $refused->retryAfter, 0.001);

        // 23:00 UTC is midnight in Douala: April.
        $this->assertSame(2, $this->allowed($this->run_($algorithm, $limit, $march + 1800, 3, $state)));
    }

    public function test_concurrency_holds_slots_until_released_or_expired(): void
    {
        $algorithm = new Concurrency;
        $limit = Limit::concurrent(2, lease: 30);
        $state = null;
        $outcomes = $this->run_($algorithm, $limit, self::T0, 3, $state);

        $this->assertSame(2, $this->allowed($outcomes));
        $this->assertNotNull($outcomes[0]->lease);
        $this->assertNotSame($outcomes[0]->lease, $outcomes[1]->lease);

        $state = $algorithm->refund($state, $limit, self::T0 + 1, 1, $outcomes[0]->lease)->state;
        $this->assertTrue($algorithm->attempt($state, $limit, self::T0 + 1, 1)->outcome->allowed);

        // A dead holder's slot frees itself when its lease runs out.
        $this->assertSame(2, $this->allowed($this->run_($algorithm, $limit, self::T0 + 31, 3, $state)));
    }

    public function test_cost_above_the_capacity_never_passes(): void
    {
        foreach ([new TokenBucket, new Gcra, new SlidingLog, new FixedWindow] as $algorithm) {
            $outcome = $algorithm->attempt(null, Limit::perMinute(5), self::T0, 6)->outcome;
            $this->assertFalse($outcome->allowed, get_class($algorithm));
        }
    }

    public function test_transitions_round_trip_through_json(): void
    {
        foreach (self::counting() as [$algorithm]) {
            $state = null;

            for ($i = 0; $i < 12; $i++) {
                $transition = $algorithm->attempt($state, Limit::perMinute(10), self::T0 + $i * 0.37, 1);

                if ($transition->write) {
                    $state = json_decode(json_encode($transition->state, JSON_PRESERVE_ZERO_FRACTION), true);
                }
            }

            $this->assertFalse($algorithm->attempt($state, Limit::perMinute(10), self::T0 + 4.5, 1)->outcome->allowed, get_class($algorithm));
        }

        $this->assertInstanceOf(Transition::class, (new FixedWindow)->attempt(null, Limit::perMinute(1), self::T0, 1));
    }
}
