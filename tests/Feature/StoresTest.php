<?php

declare(strict_types=1);

namespace Atannex\Limiting\Tests\Feature;

use Atannex\Limiting\Clock\SystemClock;
use Atannex\Limiting\Contracts\Clock;
use Atannex\Limiting\Contracts\Store;
use Atannex\Limiting\Engine;
use Atannex\Limiting\Exceptions\StoreUnavailable;
use Atannex\Limiting\Facades\Limiting;
use Atannex\Limiting\Jail\Jail;
use Atannex\Limiting\Limit;
use Atannex\Limiting\Stats\Recorder;
use Atannex\Limiting\Stores\CacheStore;
use Atannex\Limiting\Stores\RedisStore;
use Atannex\Limiting\Tests\TestCase;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Real stores. Redis tests need a server on 127.0.0.1:6390 (or
 * LIMITING_TEST_REDIS_HOST/PORT) and skip without one:
 *
 *     docker run --rm -d -p 6390:6379 redis:7-alpine
 */
final class StoresTest extends TestCase
{
    private string $cacheDir;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $this->cacheDir = sys_get_temp_dir().'/limiting-test-'.getmypid().'-'.bin2hex(random_bytes(3));
        $app['config']->set('cache.stores.shared', ['driver' => 'file', 'path' => $this->cacheDir, 'lock_path' => $this->cacheDir]);
        $app['config']->set('limiting.stores.file', ['driver' => 'cache', 'store' => 'shared', 'lock_wait' => 10]);
        $app['config']->set('limiting.stores.redis.connection', 'limiting');
        $app['config']->set('limiting.prefix', 'limiting-test:'.bin2hex(random_bytes(4)).':');
        $app['config']->set('database.redis.client', 'phpredis');
    }

    protected function tearDown(): void
    {
        if (isset($this->cacheDir) && is_dir($this->cacheDir)) {
            exec('rm -rf '.escapeshellarg($this->cacheDir));
        }

        parent::tearDown();
    }

    private function use(string $store): void
    {
        if ($store === 'redis') {
            $this->requireRedis();
        }

        config(['limiting.store' => $store]);

        foreach ([Store::class, Engine::class, Jail::class, Recorder::class] as $abstract) {
            $this->app->forgetInstance($abstract);
        }
    }

    private function requireRedis(): void
    {
        if (! extension_loaded('redis')) {
            $this->markTestSkipped('phpredis is not installed.');
        }

        try {
            $this->app['redis']->connection('limiting')->ping();
        } catch (\Throwable) {
            $this->markTestSkipped('No Redis on '.env('LIMITING_TEST_REDIS_HOST', '127.0.0.1').':'.env('LIMITING_TEST_REDIS_PORT', 6390).'.');
        }
    }

    public static function stores(): array
    {
        return ['redis' => ['redis'], 'file cache' => ['file']];
    }

    #[DataProvider('stores')]
    public function test_every_algorithm_works_on_the_store(string $store): void
    {
        $this->use($store);

        foreach (['fixed-window', 'sliding-window', 'sliding-log', 'token-bucket', 'gcra'] as $algorithm) {
            Limiting::define($algorithm, Limit::perMinute(3)->using($algorithm));
            $limiter = Limiting::for($algorithm, 'user:1');
            $results = array_map(fn () => $limiter->attempt()->allowed(), range(1, 4));

            $this->assertSame([true, true, true, false], $results, $algorithm);
            $limiter->reset();
            $this->assertTrue($limiter->attempt()->allowed(), "{$algorithm} after reset");
        }

        Limiting::define('parallel', Limit::concurrent(1));
        $first = Limiting::for('parallel')->attempt();
        $this->assertTrue(Limiting::for('parallel')->attempt()->denied());
        $first->release();
        $this->assertTrue(Limiting::for('parallel')->attempt()->allowed());

        Limiting::ban('203.0.113.9', 60);
        $this->assertTrue(Limiting::jail()->isJailed('ip:203.0.113.9'));
        $this->assertCount(1, Limiting::jail()->all());
    }

    #[DataProvider('stores')]
    public function test_racing_processes_never_get_more_than_the_limit(string $store): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('Needs pcntl and posix.');
        }

        $this->use($store);
        $this->app->instance(Clock::class, new SystemClock);
        $this->use($store);
        Limiting::define('race', Limit::perMinute(50)->using('sliding-window'));

        $workers = 8;
        $tries = 20;
        $dir = sys_get_temp_dir().'/limiting-race-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $children = [];

        for ($w = 0; $w < $workers; $w++) {
            $pid = pcntl_fork();

            if ($pid === 0) {
                if ($store === 'redis') {
                    $this->app['redis']->purge('limiting');
                }

                $allowed = 0;

                for ($i = 0; $i < $tries; $i++) {
                    $allowed += Limiting::for('race', 'user:race')->attempt()->allowed() ? 1 : 0;
                }

                file_put_contents("{$dir}/{$w}", (string) $allowed);
                posix_kill(getmypid(), SIGKILL);
            }

            $children[] = $pid;
        }

        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $total = array_sum(array_map(fn ($file) => (int) file_get_contents($file), glob("{$dir}/*")));
        exec('rm -rf '.escapeshellarg($dir));

        $this->assertCount($workers, $children);
        $this->assertSame(50, $total, "{$workers} processes × {$tries} attempts against a limit of 50");
    }

    public function test_redis_store_survives_a_connection_prefix(): void
    {
        $this->requireRedis();
        config(['database.redis.limiting.options' => ['prefix' => 'someapp:']]);
        $this->app['redis']->purge('limiting');
        $this->use('redis');

        Limiting::define('prefixed', Limit::perMinute(2));
        $limiter = Limiting::for('prefixed', 'user:1');

        $this->assertSame([true, true, false], [$limiter->attempt()->allowed(), $limiter->attempt()->allowed(), $limiter->attempt()->allowed()]);
    }

    public function test_redis_store_reports_server_time(): void
    {
        $this->requireRedis();
        $this->use('redis');
        $store = $this->app->make(Store::class);

        $this->assertInstanceOf(RedisStore::class, $store);
        $this->assertEqualsWithDelta(microtime(true), $store->time(), 2.0);
    }

    public function test_cache_store_needs_locks(): void
    {
        $this->expectException(StoreUnavailable::class);
        new CacheStore(new Repository(new class implements \Illuminate\Contracts\Cache\Store
        {
            public function get($key) {}

            public function many(array $keys) {}

            public function put($key, $value, $seconds) {}

            public function putMany(array $values, $seconds) {}

            public function increment($key, $value = 1) {}

            public function decrement($key, $value = 1) {}

            public function forever($key, $value) {}

            public function forget($key) {}

            public function flush() {}

            public function getPrefix() {}
        }));
    }
}
