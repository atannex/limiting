<?php

declare(strict_types=1);

namespace Atannex\Limiting\Routing;

use Atannex\Limiting\Http\Middleware\LimitRequests;
use Atannex\Routing\Contracts\RouteModifier;
use Atannex\Routing\Definition\RouteDefinition;
use Attribute;

/**
 * Route attribute for atannex/routing:
 *
 *     #[RateLimit('api')]                                               // a named policy
 *     #[RateLimit('api', cost: 5)]                                      // an expensive route
 *     #[RateLimit(30, per: '1 minute')]                                 // inline, per route
 *     #[RateLimit(5, per: '1 minute', by: 'email:email|ip', count: 'failures', blockFor: '5 minutes')]
 *
 * Repeat it to stack limits; a request must pass them all.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class RateLimit implements RouteModifier
{
    /** @param  string|list<int>|null  $count  'failures', status codes, or null for every request */
    public function __construct(
        public string|int $policy,
        public string|int|null $per = null,
        public ?string $by = null,
        public ?string $algorithm = null,
        public ?int $burst = null,
        public int $cost = 1,
        public string|int|null $blockFor = null,
        public string|array|null $count = null,
        public ?bool $failClosed = null,
        public bool $shadow = false,
        public ?string $name = null,
    ) {}

    public function middleware(): string
    {
        if (is_string($this->policy) && ! ctype_digit($this->policy)) {
            return LimitRequests::using($this->policy, $this->cost);
        }

        $options = array_filter([
            'per' => $this->per === null ? null : (is_int($this->per) ? $this->per.'s' : str_replace(' ', '', $this->per)),
            'by' => $this->by,
            'algo' => $this->algorithm,
            'burst' => $this->burst,
            'cost' => $this->cost > 1 ? $this->cost : null,
            'block' => $this->blockFor === null ? null : (is_int($this->blockFor) ? $this->blockFor.'s' : str_replace(' ', '', $this->blockFor)),
            'count' => is_array($this->count) ? implode(';', $this->count) : $this->count,
            'fail' => $this->failClosed === null ? null : ($this->failClosed ? 'closed' : 'open'),
            'shadow' => $this->shadow ? '1' : null,
            'name' => $this->name,
        ], static fn ($value) => $value !== null);

        $arguments = [(string) $this->policy];

        foreach ($options as $key => $value) {
            $arguments[] = "{$key}={$value}";
        }

        return 'limit:'.implode(',', $arguments);
    }

    public function modify(RouteDefinition $route): void
    {
        $route->middleware($this->middleware());
    }
}
