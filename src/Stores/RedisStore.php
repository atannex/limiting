<?php

declare(strict_types=1);

namespace Atannex\Limiting\Stores;

use Atannex\Limiting\Contracts\Store;
use Atannex\Limiting\Exceptions\StoreUnavailable;
use Closure;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Redis\Connections\Connection;

/**
 * Redis, lock-free: read, compute, then write with a Lua compare-and-set
 * that only succeeds if nobody changed the key in between; otherwise read
 * again and retry. One round trip to read and one to write, atomic on the
 * server, and it works with phpredis, predis and Redis Cluster (each update
 * touches a single key).
 */
final class RedisStore implements Store
{
    private const CAS = <<<'LUA'
        local current = redis.call('GET', KEYS[1])
        if ARGV[1] == '0' then
            if current then return 0 end
        elseif current ~= ARGV[2] then
            return 0
        end
        if ARGV[3] == '' then
            redis.call('DEL', KEYS[1])
        else
            redis.call('SET', KEYS[1], ARGV[3], 'PX', ARGV[4])
        end
        return 1
        LUA;

    public function __construct(
        private readonly Factory $redis,
        private readonly ?string $connection = null,
        private readonly int $retries = 64,
    ) {}

    public function get(string $key): ?array
    {
        return $this->decode($this->read($key));
    }

    public function update(string $key, Closure $callback): mixed
    {
        for ($attempt = 0; $attempt <= $this->retries; $attempt++) {
            $raw = $this->read($key);
            [$state, $ttl, $result, $write] = $callback($this->decode($raw));

            if (! $write) {
                return $result;
            }

            $next = $state === null ? '' : json_encode($state, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);

            if ($next === $raw) {
                return $result;
            }

            $swapped = $this->run(fn (Connection $redis) => $redis->eval(
                self::CAS,
                1,
                $key,
                $raw === null ? '0' : '1',
                $raw ?? '',
                $next,
                (string) max(1, (int) ceil($ttl * 1000)),
            ));

            if ((int) $swapped === 1) {
                return $result;
            }

            usleep(random_int(0, min(2000, 50 * ($attempt + 1))));
        }

        throw new StoreUnavailable("Gave up on {$key} after {$this->retries} conflicting writes.");
    }

    public function put(string $key, array $value, float $ttl): void
    {
        $this->run(fn (Connection $redis) => $redis->eval(
            "redis.call('SET', KEYS[1], ARGV[1], 'PX', ARGV[2]) return 1",
            1,
            $key,
            json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            (string) max(1, (int) ceil($ttl * 1000)),
        ));
    }

    public function forget(string $key): void
    {
        $this->run(fn (Connection $redis) => $redis->eval("return redis.call('DEL', KEYS[1])", 1, $key));
    }

    /** Server time, for the store clock. */
    public function time(): float
    {
        $time = $this->run(fn (Connection $redis) => $redis->eval("local t = redis.call('TIME') return t[1] .. '.' .. string.format('%06d', t[2])", 0));

        return (float) $time;
    }

    private function read(string $key): ?string
    {
        $value = $this->run(fn (Connection $redis) => $redis->eval("return redis.call('GET', KEYS[1])", 1, $key));

        return is_string($value) ? $value : null;
    }

    private function decode(?string $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $value = json_decode($raw, true);

        return is_array($value) ? $value : null;
    }

    private function run(Closure $command): mixed
    {
        try {
            return $command($this->redis->connection($this->connection));
        } catch (StoreUnavailable $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new StoreUnavailable('Redis is unavailable: '.$e->getMessage(), 0, $e);
        }
    }
}
