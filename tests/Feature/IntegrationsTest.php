<?php

declare(strict_types=1);

namespace Atannex\Limiting\Tests\Feature;

use Atannex\Limiting\Exceptions\RateLimitExceeded;
use Atannex\Limiting\Facades\Limiting;
use Atannex\Limiting\Limit;
use Atannex\Limiting\Queue\LimitJob;
use Atannex\Limiting\Routing\RateLimit;
use Atannex\Limiting\Tests\TestCase;
use Atannex\Routing\Definition\RouteDefinition;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

final class IntegrationsTest extends TestCase
{
    public function test_jobs_over_the_limit_are_released_for_later(): void
    {
        Limiting::define('provider', Limit::perMinute(1));
        $job = new class
        {
            public ?int $releasedFor = null;

            public int $ran = 0;

            public function release(int $delay): void
            {
                $this->releasedFor = $delay;
            }
        };
        $middleware = new LimitJob('provider', fn ($job) => 'tenant:7');
        $next = function ($job) {
            $job->ran++;
        };

        $middleware->handle($job, $next);
        $middleware->handle($job, $next);

        $this->assertSame(1, $job->ran);
        $this->assertSame(60, $job->releasedFor);
    }

    public function test_jobs_can_be_dropped_or_failed_instead(): void
    {
        Limiting::define('provider', Limit::perMinute(1));
        $job = new class
        {
            public bool $released = false;

            public ?\Throwable $failed = null;

            public function release(int $delay): void
            {
                $this->released = true;
            }

            public function fail(\Throwable $e): void
            {
                $this->failed = $e;
            }
        };

        (new LimitJob('provider'))->handle($job, fn () => null);
        (new LimitJob('provider'))->dontRelease()->handle($job, fn () => null);
        $this->assertFalse($job->released);

        (new LimitJob('provider'))->failWhenLimited()->handle($job, fn () => null);
        $this->assertInstanceOf(RateLimitExceeded::class, $job->failed);
    }

    public function test_outgoing_calls_wait_their_turn_and_back_off_on_upstream_429(): void
    {
        Limiting::define('upstream', Limit::perSecond(1)->gcra(burst: 1));
        $mock = new MockHandler([
            new Response(200),
            new Response(429, ['Retry-After' => '30']),
            new Response(200),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Limiting::guzzle('upstream', wait: 60));
        $client = new Client(['handler' => $stack, 'http_errors' => false]);

        $start = $this->clock->now();
        $this->assertSame(200, $client->get('https://example.test')->getStatusCode());
        $this->assertSame(429, $client->get('https://example.test')->getStatusCode());
        $this->assertEqualsWithDelta(1.0, $this->clock->now() - $start, 0.1, 'the second call waited its turn');

        // The upstream asked for 30 s: everybody waits that long.
        $this->assertTrue(Limiting::for('upstream')->tooManyAttempts());
        $this->assertSame(200, $client->get('https://example.test')->getStatusCode());
        $this->assertGreaterThanOrEqual(30.0, $this->clock->now() - $start);
    }

    public function test_outgoing_calls_give_up_after_the_wait(): void
    {
        Limiting::define('quota', Limit::perHour(1));
        $stack = HandlerStack::create(new MockHandler([new Response(200), new Response(200)]));
        $stack->push(Limiting::guzzle('quota', wait: 5));
        $client = new Client(['handler' => $stack]);

        $client->get('https://example.test');
        $this->expectException(RateLimitExceeded::class);
        $client->get('https://example.test');
    }

    public function test_the_route_attribute_adds_the_middleware(): void
    {
        $definition = new RouteDefinition(['GET'], '/x', 'Controller', 'x');
        (new RateLimit('login'))->modify($definition);
        (new RateLimit(5, per: '10 minutes', by: 'email:email|ip', count: 'failures', blockFor: '5 minutes', failClosed: true))->modify($definition);

        $this->assertSame([
            'limit:login',
            'limit:5,per=10minutes,by=email:email|ip,block=5minutes,count=failures,fail=closed',
        ], $definition->middleware);
    }
}
