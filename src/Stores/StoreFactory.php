<?php

declare(strict_types=1);

namespace Atannex\Limiting\Stores;

use Atannex\Limiting\Contracts\Clock;
use Atannex\Limiting\Contracts\Store;
use Atannex\Limiting\Exceptions\InvalidLimit;
use Closure;
use Illuminate\Contracts\Foundation\Application;

final class StoreFactory
{
    /** @var array<string, Closure(array<string, mixed>, Application): Store> */
    private array $drivers = [];

    public function __construct(private readonly Application $app) {}

    /** @param  Closure(array<string, mixed>, Application): Store  $factory */
    public function extend(string $driver, Closure $factory): void
    {
        $this->drivers[$driver] = $factory;
    }

    public function make(string $name): Store
    {
        $config = (array) $this->app['config']->get("limiting.stores.{$name}", ['driver' => $name]);
        $driver = (string) ($config['driver'] ?? $name);

        if (isset($this->drivers[$driver])) {
            return ($this->drivers[$driver])($config, $this->app);
        }

        return match ($driver) {
            'cache' => new CacheStore(
                $this->app->make('cache')->store($config['store'] ?? null),
                (float) ($config['lock_wait'] ?? 3),
                (int) ($config['lock_seconds'] ?? 5),
            ),
            'redis' => new RedisStore($this->app->make('redis'), $config['connection'] ?? null, (int) ($config['retries'] ?? 64)),
            'memory' => new MemoryStore($this->app->make(Clock::class)),
            default => throw new InvalidLimit("Unknown limiting store driver \"{$driver}\"."),
        };
    }
}
