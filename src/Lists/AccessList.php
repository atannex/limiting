<?php

declare(strict_types=1);

namespace Atannex\Limiting\Lists;

use Atannex\Limiting\Context;
use Atannex\Limiting\Subjects\IpAddress;
use Atannex\Limiting\Subjects\SubjectResolver;
use Closure;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Allow and deny lists. Entries are IP addresses, CIDR ranges
 * (10.0.0.0/8, 2001:db8::/32) or subject labels ('user:1', 'token:…').
 * Allowed senders skip every limit; denied senders are refused outright.
 * Deny wins over allow.
 */
final class AccessList
{
    /** @var list<Closure(Context): bool> */
    private array $bypasses = [];

    /**
     * @param  list<string>  $allow
     * @param  list<string>  $deny
     */
    public function __construct(
        private readonly SubjectResolver $subjects,
        private array $allow = [],
        private array $deny = [],
    ) {}

    public function allows(Context $context): bool
    {
        if ($this->matches($this->allow, $context)) {
            return true;
        }

        foreach ($this->bypasses as $bypass) {
            if ($bypass($context)) {
                return true;
            }
        }

        return false;
    }

    public function denies(Context $context): bool
    {
        return $this->matches($this->deny, $context);
    }

    public function allow(string ...$entries): void
    {
        array_push($this->allow, ...$entries);
    }

    public function deny(string ...$entries): void
    {
        array_push($this->deny, ...$entries);
    }

    /** @param  Closure(Context): bool  $callback */
    public function bypass(Closure $callback): void
    {
        $this->bypasses[] = $callback;
    }

    /** @return array{allow: list<string>, deny: list<string>} */
    public function entries(): array
    {
        return ['allow' => $this->allow, 'deny' => $this->deny];
    }

    /** @param  list<string>  $entries */
    private function matches(array $entries, Context $context): bool
    {
        if ($entries === []) {
            return false;
        }

        $ip = $context->request?->ip();
        $ranges = [];
        $labels = [];

        foreach ($entries as $entry) {
            $entry = trim((string) $entry);

            if ($entry === '') {
                continue;
            }

            if (IpAddress::isIp(explode('/', $entry, 2)[0]) && ! str_contains($entry, '|')) {
                $ranges[] = $entry;
            } else {
                $labels[] = $this->subjects->normalizeLabel($entry);
            }
        }

        if ($ip !== null && $ranges !== [] && IpUtils::checkIp($ip, $ranges)) {
            return true;
        }

        return $labels !== [] && array_intersect($labels, $this->subjects->identities($context)) !== [];
    }
}
