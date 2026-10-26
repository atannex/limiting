<?php

declare(strict_types=1);

namespace Atannex\Limiting\Stores;

use Atannex\Limiting\Contracts\Store;
use Atannex\Limiting\Exceptions\StoreUnavailable;
use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;

/**
 * Any Laravel cache store that supports atomic locks: redis, memcached,
 * database, dynamodb, file, array. Each update holds a short lock on the key,
 * so concurrent requests can't both spend the last unit.
 *
 * Correct everywhere, but a lock costs round trips; with Redis, the redis
 * store is faster.
 */
final class CacheStore implements Store
{
    public function __construct(
        private readonly Repository $cache,
        private readonly float $lockWait = 3.0,
        private readonly int $lockSeconds = 5,
    ) {
        if (! $cache->getStore() instanceof LockProvider) {
            throw new StoreUnavailable('The cache store '.get_class($cache->getStore()).' has no atomic locks, so it cannot keep rate limits exact.');
        }
    }

    public function get(string $key): ?array
    {
        $value = $this->cache->get($key);

        return is_array($value) ? $value : null;
    }

    public function update(string $key, Closure $callback): mixed
    {
        /** @var LockProvider $store */
        $store = $this->cache->getStore();
        $lock = $store->lock($key.':lock', $this->lockSeconds);

        try {
            return $lock->block($this->lockWait, function () use ($key, $callback) {
                [$state, $ttl, $result, $write] = $callback($this->get($key));

                if ($write) {
                    $state === null ? $this->forget($key) : $this->put($key, $state, $ttl);
                }

                return $result;
            });
        } catch (LockTimeoutException $e) {
            throw new StoreUnavailable("Timed out waiting for the lock on {$key}.", 0, $e);
        }
    }

    public function put(string $key, array $value, float $ttl): void
    {
        $this->cache->put($key, $value, max(1, (int) ceil($ttl)));
    }

    public function forget(string $key): void
    {
        $this->cache->forget($key);
    }

    public function repository(): Repository
    {
        return $this->cache;
    }
}
