<?php

declare(strict_types=1);

namespace Atannex\Limiting\Contracts;

use Closure;

/**
 * Where counters live. Every implementation must make `update()` atomic for
 * a key: no two processes may interleave between its read and its write.
 */
interface Store
{
    /** @return array<string, mixed>|null */
    public function get(string $key): ?array;

    /**
     * Read the state, pass it to `$callback`, save what it returns.
     *
     * The callback returns a Transition-like tuple: [array|null $state,
     * float $ttl, mixed $result, bool $write]. A null state deletes the key,
     * `$write = false` leaves it untouched. The callback can run more than
     * once (optimistic stores retry on conflict), so it must be pure.
     *
     * @param  Closure(array<string, mixed>|null): array{0: array<string, mixed>|null, 1: float, 2: mixed, 3: bool}  $callback
     */
    public function update(string $key, Closure $callback): mixed;

    /** @param  array<string, mixed>  $value */
    public function put(string $key, array $value, float $ttl): void;

    public function forget(string $key): void;
}
