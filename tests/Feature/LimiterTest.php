<?php

declare(strict_types=1);

namespace Atannex\Limiting\Tests\Feature;

use Atannex\Limiting\Contracts\Store;
use Atannex\Limiting\Decision;
use Atannex\Limiting\Engine;
use Atannex\Limiting\Events\Jailed;
use Atannex\Limiting\Events\Limited;
use Atannex\Limiting\Events\StoreFailed;
use Atannex\Limiting\Events\WouldLimit;
use Atannex\Limiting\Exceptions\RateLimitExceeded;
use Atannex\Limiting\Exceptions\StoreUnavailable;
use Atannex\Limiting\Exceptions\UnknownPolicy;
use Atannex\Limiting\Facades\Limiting;
use Atannex\Limiting\Jail\Jail;
use Atannex\Limiting\Limit;
use Atannex\Limiting\Stats\Recorder;
use Atannex\Limiting\Tests\TestCase;
use Closure;
use Illuminate\Support\Facades\Event;

final class LimiterTest extends TestCase
{
    public function test_a_policy_allows_its_limit_per_subject(): void
    {
        Limiting::define('send', Limit::perMinute(3));

        $alice = Limiting::for('send', 'user:alice');

        $this->assertTrue($alice->attempt()->allowed());
        $this->assertTrue($alice->attempt()->allowed());
        $this->assertSame(1, $alice->remaining());
        $this->assertTrue($alice->attempt()->allowed());
        $this->assertTrue($alice->tooManyAttempts());
        $this->assertFalse($alice->attempt()->allowed());
        $this->assertTrue(Limiting::for('send', 'user:bob')->attempt()->allowed(), 'subjects count apart');

        $this->advance(61);
        $this->assertTrue($alice->attempt()->allowed());
    }

    public function test_every_limit_must_pass_and_refusals_cost_nothing(): void
    {
        Limiting::define('api', [
            Limit::perMinute(100)->named('minute'),
            Limit::perSecond(2)->named('second'),
        ]);

        $limiter = Limiting::for('api', 'user:1');
        $limiter->attempt();
        $limiter->attempt();
        $refused = $limiter->attempt();

        $this->assertTrue($refused->denied());
        $this->assertSame('second', $refused->refusals()[0]->limit);

        // The refused attempt was given back on the minute limit.
        $minute = $limiter->check()->decision('minute');
        $this->assertSame(97, $minute->remaining);
    }

    public function test_costs_multiply(): void
    {
        Limiting::define('export', Limit::perHour(10)->cost(4));
        $limiter = Limiting::for('export', 'user:1');

        $this->assertTrue($limiter->attempt()->allowed());
        $this->assertTrue($limiter->attempt()->allowed());
        $this->assertFalse($limiter->attempt()->allowed());
        $this->assertTrue(Limiting::for(Limit::perHour(10), 'user:1')->attempt(10)->allowed());
    }

    public function test_reset_and_refund(): void
    {
        Limiting::define('send', Limit::perMinute(1));
        $limiter = Limiting::for('send', 'user:1');
        $limiter->attempt();
        $this->assertTrue($limiter->tooManyAttempts());

        $limiter->refund();
        $this->assertFalse($limiter->tooManyAttempts());

        $limiter->attempt();
        $limiter->reset();
        $this->assertTrue($limiter->attempt()->allowed());
    }

    public function test_attempt_runs_the_callback_only_when_allowed(): void
    {
        $runs = 0;
        $send = function () use (&$runs) {
            return ++$runs;
        };

        $this->assertSame(1, Limiting::attempt('otp:1', 2, $send));
        $this->assertSame(2, Limiting::attempt('otp:1', 2, $send));
        $this->assertFalse(Limiting::attempt('otp:1', 2, $send));
        $this->assertSame(1, Limiting::attempt('otp:2', Limit::perMinute(2), fn () => 1));
    }

    public function test_or_fail_throws_a_429(): void
    {
        Limiting::define('send', Limit::perMinute(1));
        Limiting::for('send', 'user:1')->orFail();

        try {
            Limiting::for('send', 'user:1')->orFail();
            $this->fail('No exception');
        } catch (RateLimitExceeded $e) {
            $this->assertSame(429, $e->getStatusCode());
            $this->assertSame('60', $e->getHeaders()['Retry-After']);
        }
    }

    public function test_wait_sleeps_until_allowed_within_the_timeout(): void
    {
        Limiting::define('upstream', Limit::perSecond(2)->gcra());
        $limiter = Limiting::for('upstream');
        $start = $this->clock->now();

        for ($i = 0; $i < 6; $i++) {
            $limiter->wait(5);
        }

        $this->assertEqualsWithDelta(2.0, $this->clock->now() - $start, 0.1);

        $this->expectException(RateLimitExceeded::class);
        Limiting::define('slow', Limit::perHour(1));
        Limiting::for('slow')->wait(1);
        Limiting::for('slow')->wait(1);
    }

    public function test_block_for_keeps_refusing_after_the_window(): void
    {
        Limiting::define('codes', Limit::perMinute(2)->blockFor('10 minutes'));
        $limiter = Limiting::for('codes', 'user:1');
        $limiter->attempt();
        $limiter->attempt();

        $refused = $limiter->attempt();
        $this->assertSame(Decision::BLOCKED, $refused->decisions()[0]->reason);
        $this->assertEqualsWithDelta(600, $refused->retryAfter(), 1);

        $this->advance(120);
        $this->assertTrue($limiter->attempt()->denied(), 'still blocked');

        $this->advance(481);
        $this->assertTrue($limiter->attempt()->allowed());
    }

    public function test_repeat_offenders_are_jailed_with_escalating_sentences(): void
    {
        Event::fake([Jailed::class]);
        Limiting::define('spray', Limit::perMinute(1)->by('global')->jail(after: 2, within: '1 hour', for: '10 minutes', escalate: 3, max: '1 hour', by: 'global'));
        Limiting::define('other', Limit::perMinute(100));
        $limiter = Limiting::for('spray', 'ip:203.0.113.9');

        $limiter->attempt();
        $limiter->attempt();           // strike 1
        $jailed = $limiter->attempt(); // strike 2: jailed

        $this->assertTrue($jailed->jailed());
        $this->assertEqualsWithDelta(600, $jailed->retryAfter(), 1);
        Limiting::assertJailed('203.0.113.9');
        $this->assertTrue(Limiting::for('other', 'ip:203.0.113.9')->attempt()->jailed(), 'the jail covers every policy');
        Event::assertDispatched(Jailed::class, fn (Jailed $e) => $e->subject === 'ip:203.0.113.9' && $e->sentence === 1);

        // Second sentence: three times longer.
        $this->advance(601);
        $limiter->reset();
        $limiter->attempt();
        $limiter->attempt();
        $this->assertEqualsWithDelta(1800, $limiter->attempt()->retryAfter(), 1);

        // Third: capped at the max.
        $this->advance(1801);
        $limiter->reset();
        $limiter->attempt();
        $limiter->attempt();
        $this->assertEqualsWithDelta(3600, $limiter->attempt()->retryAfter(), 1);
    }

    public function test_manual_bans_and_releases(): void
    {
        Limiting::define('any', Limit::perMinute(100));
        Limiting::ban('2001:db8:1:2::77', '1 hour', 'abuse');

        $this->assertTrue(Limiting::for('any', 'ip:2001:db8:1:2::/64')->attempt()->jailed());
        $this->assertCount(1, Limiting::jail()->all());
        $this->assertSame('abuse', Limiting::jail()->all()[0]['reason']);

        $this->assertTrue(Limiting::unban('2001:db8:1:2:ffff::1'));
        $this->assertTrue(Limiting::for('any', 'ip:2001:db8:1:2::/64')->attempt()->allowed());
        $this->assertSame([], Limiting::jail()->all());
    }

    public function test_shadow_limits_report_but_let_through(): void
    {
        Event::fake([WouldLimit::class, Limited::class]);
        Limiting::define('new', Limit::perMinute(1)->shadow());
        $limiter = Limiting::for('new', 'user:1');

        $limiter->attempt();
        $verdict = $limiter->attempt();

        $this->assertTrue($verdict->allowed());
        $this->assertTrue($verdict->shadowed());
        Event::assertDispatched(WouldLimit::class);
        Event::assertNotDispatched(Limited::class);
    }

    public function test_dry_run_shadows_everything(): void
    {
        config(['limiting.dry_run' => true]);
        $this->app->forgetInstance(Engine::class);
        $this->app->forgetInstance(\Atannex\Limiting\Limiting::class);
        Limiting::clearResolvedInstances();
        config(['limiting.dry_run' => true]);

        Limiting::define('send', Limit::perMinute(1));
        Limiting::for('send')->attempt();
        $this->assertTrue(Limiting::for('send')->attempt()->allowed());
    }

    public function test_store_failures_fail_open_or_closed(): void
    {
        Event::fake([StoreFailed::class]);
        $this->app->instance(Store::class, new class implements Store
        {
            public function get(string $key): ?array
            {
                throw new StoreUnavailable('down');
            }

            public function update(string $key, Closure $callback): mixed
            {
                throw new StoreUnavailable('down');
            }

            public function put(string $key, array $value, float $ttl): void
            {
                throw new StoreUnavailable('down');
            }

            public function forget(string $key): void {}
        });
        foreach ([Engine::class, Jail::class, Recorder::class] as $abstract) {
            $this->app->forgetInstance($abstract);
        }

        Limiting::define('open', Limit::perMinute(1));
        Limiting::define('closed', Limit::perMinute(1)->failClosed());

        $open = Limiting::for('open')->attempt();
        $this->assertTrue($open->allowed());
        $this->assertTrue($open->degraded());

        $closed = Limiting::for('closed')->attempt();
        $this->assertTrue($closed->denied());
        $this->assertSame(30.0, $closed->retryAfter());
        Event::assertDispatched(StoreFailed::class, fn (StoreFailed $e) => $e->failedClosed && $e->policy === 'closed');
        Event::assertDispatched(StoreFailed::class, fn (StoreFailed $e) => ! $e->failedClosed && $e->policy === 'open');
    }

    public function test_allow_and_deny_lists(): void
    {
        Limiting::define('send', Limit::perMinute(1));
        Limiting::allow('user:staff');
        Limiting::deny('user:spammer');

        $staff = Limiting::for('send', 'user:staff');
        $staff->attempt();
        $this->assertTrue($staff->attempt()->bypassed());
        $this->assertTrue(Limiting::for('send', 'user:spammer')->attempt()->forbidden());
    }

    public function test_conditions_and_dynamic_policies(): void
    {
        Limiting::define('plans', fn ($request, $context) => str_starts_with((string) $context->subject, 'pro:')
            ? Limit::none()
            : Limit::perMinute(1));

        Limiting::for('plans', 'pro:1')->attempt();
        $this->assertTrue(Limiting::for('plans', 'pro:1')->attempt()->allowed());
        Limiting::for('plans', 'free:1')->attempt();
        $this->assertTrue(Limiting::for('plans', 'free:1')->attempt()->denied());

        Limiting::define('weekday', Limit::perMinute(1)->when(fn () => false));
        Limiting::for('weekday')->attempt();
        $this->assertTrue(Limiting::for('weekday')->attempt()->allowed());
    }

    public function test_concurrency_slots_are_released_by_run(): void
    {
        Limiting::define('reports', Limit::concurrent(1));
        $limiter = Limiting::for('reports', 'user:1');

        $inner = null;
        $limiter->run(function () use ($limiter, &$inner) {
            $inner = $limiter->attempt()->allowed();
        });

        $this->assertFalse($inner, 'second caller refused while the first runs');
        $this->assertTrue($limiter->attempt()->allowed(), 'slot given back afterwards');
    }

    public function test_config_policies_are_ready(): void
    {
        $this->assertTrue(Limiting::has('login'));
        $this->assertCount(3, Limiting::policy('login')->fixedLimits());
        $this->assertSame(['rate', 'daily'], array_map(fn ($l) => $l->name, Limiting::policy('api')->fixedLimits()));

        $this->expectException(UnknownPolicy::class);
        Limiting::policy('nope');
    }

    public function test_stats_count_refusals(): void
    {
        Limiting::define('send', Limit::perMinute(1));
        Limiting::for('send', 'user:1')->attempt();
        Limiting::for('send', 'user:1')->attempt();
        Limiting::for('send', 'user:1')->attempt();

        $summary = Limiting::stats()->summary();
        $this->assertSame(['limit' => 2], array_values($summary['limits'])[0]);
        $this->assertSame(['user:1' => 2], $summary['subjects']);
    }

    public function test_fake_records_and_asserts(): void
    {
        Limiting::fake();
        Limiting::define('send', Limit::perMinute(1));

        Limiting::for('send')->attempt();
        Limiting::for('send')->attempt();

        Limiting::assertAttempted('send', 2);
        Limiting::assertLimited('send', 1);
        Limiting::assertNotAttempted('other');
    }
}
