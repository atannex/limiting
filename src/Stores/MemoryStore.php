<?php

declare(strict_types=1);

namespace Atannex\Limiting\Stores;

use Atannex\Limiting\Contracts\Clock;
use Atannex\Limiting\Contracts\Store;
use Closure;

/**
 * Counters in PHP memory: one process only. For tests, and for limits that
 * are meant to be per worker. Never use it for HTTP limits under php-fpm,
 * where every request starts empty (limiting:doctor flags it).
 */
final class MemoryStore implements Store
{
    /** @var array<string, array{0: array<string, mixed>, 1: float}> */
    private array $items = [];

    public function __construct(private readonly Clock $clock) {}

    public function get(string $key): ?array
    {
        if (! isset($this->items[$key])) {
            return null;
        }

        [$value, $expires] = $this->items[$key];

        if ($expires <= $this->clock->now()) {
            unset($this->items[$key]);

            return null;
        }

        return $value;
    }

    public function update(string $key, Closure $callback): mixed
    {
        [$state, $ttl, $result, $write] = $callback($this->get($key));

        if ($write) {
            $state === null ? $this->forget($key) : $this->put($key, $state, $ttl);
        }

        return $result;
    }

    public function put(string $key, array $value, float $ttl): void
    {
        $this->items[$key] = [$value, $this->clock->now() + max(0.001, $ttl)];
    }

    public function forget(string $key): void
    {
        unset($this->items[$key]);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys(array_filter($this->items, fn (array $item): bool => $item[1] > $this->clock->now()));
    }

    public function flush(): void
    {
        $this->items = [];
    }
}
