<?php

declare(strict_types=1);

namespace Atannex\Limiting\Tests;

use Atannex\Limiting\Clock\FrozenClock;
use Atannex\Limiting\Contracts\Clock;
use Atannex\Limiting\Facades\Limiting;
use Atannex\Limiting\LimitingServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected FrozenClock $clock;

    protected function getPackageProviders($app): array
    {
        return [LimitingServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['Limiting' => Limiting::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('cache.default', 'array');
        $app['config']->set('limiting.store', 'cache');
        $app['config']->set('database.redis.limiting', [
            'host' => env('LIMITING_TEST_REDIS_HOST', '127.0.0.1'),
            'port' => (int) env('LIMITING_TEST_REDIS_PORT', 6390),
            'database' => 0,
        ]);

        $this->clock = new FrozenClock(1_700_000_000.0);
        $app->instance(Clock::class, $this->clock);
    }

    protected function advance(float $seconds): void
    {
        $this->clock->advance($seconds);
    }
}
