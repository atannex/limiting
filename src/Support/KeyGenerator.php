<?php

declare(strict_types=1);

namespace Atannex\Limiting\Support;

/**
 * Store keys. Subjects go through HMAC-SHA256 with a secret, so the store
 * never holds an IP address, e-mail or token in the clear, keys have a fixed
 * length whatever the input, and nobody can craft input that collides with
 * (or resets) somebody else's counter.
 */
final class KeyGenerator
{
    public function __construct(
        private readonly string $secret,
        private readonly string $prefix = 'limiting:',
    ) {}

    public function counter(string $policy, string $limit, string $subject): string
    {
        return $this->prefix.'c:'.$this->hash("{$policy}\0{$limit}\0{$subject}");
    }

    public function block(string $policy, string $limit, string $subject): string
    {
        return $this->prefix.'b:'.$this->hash("{$policy}\0{$limit}\0{$subject}");
    }

    public function strikes(string $policy, string $limit, string $subject): string
    {
        return $this->prefix.'s:'.$this->hash("{$policy}\0{$limit}\0{$subject}");
    }

    public function jail(string $subject): string
    {
        return $this->prefix.'j:'.$this->hash($subject);
    }

    public function record(string $subject): string
    {
        return $this->prefix.'r:'.$this->hash($subject);
    }

    public function jailIndex(): string
    {
        return $this->prefix.'jail-index';
    }

    public function stats(string $hour): string
    {
        return $this->prefix.'stats:'.$hour;
    }

    public function probe(): string
    {
        return $this->prefix.'probe:'.bin2hex(random_bytes(6));
    }

    public function hash(string $value): string
    {
        return substr(hash_hmac('sha256', $value, $this->secret), 0, 40);
    }
}
