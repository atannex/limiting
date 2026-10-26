<?php

declare(strict_types=1);

namespace Atannex\Limiting\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Atannex\Limiting\Policy define(string $name, \Atannex\Limiting\Limit|array|\Closure $definition)
 * @method static bool has(string $name)
 * @method static \Atannex\Limiting\Policy policy(string $name)
 * @method static array<string, \Atannex\Limiting\Policy> policies()
 * @method static \Atannex\Limiting\Limiter for(string|\Atannex\Limiting\Policy|\Atannex\Limiting\Limit|array $policy, \Illuminate\Http\Request|\Atannex\Limiting\Context|string|int|array|null $subject = null)
 * @method static mixed attempt(string $key, \Atannex\Limiting\Limit|int $limit, \Closure $callback, int|float|string|\DateInterval $per = 60)
 * @method static \Atannex\Limiting\Jail\Jail jail()
 * @method static void ban(string $subject, int|float|string|\DateInterval $duration = '1 hour', string $reason = 'manual')
 * @method static bool unban(string $subject)
 * @method static \Atannex\Limiting\Lists\AccessList lists()
 * @method static \Atannex\Limiting\Limiting allow(string ...$entries)
 * @method static \Atannex\Limiting\Limiting deny(string ...$entries)
 * @method static \Atannex\Limiting\Limiting bypass(\Closure $callback)
 * @method static \Atannex\Limiting\Limiting respondUsing(\Closure $responder)
 * @method static \Atannex\Limiting\Http\Client\GuzzleMiddleware guzzle(string|\Atannex\Limiting\Policy|\Atannex\Limiting\Limit|array $policy, string|int|array|null $subject = null, int|float|string $wait = 10)
 * @method static string middleware(string $policy, int $cost = 1)
 * @method static \Atannex\Limiting\Limiting extend(string $name, \Atannex\Limiting\Contracts\Algorithm|\Closure $algorithm)
 * @method static \Atannex\Limiting\Engine engine()
 * @method static \Atannex\Limiting\Contracts\Store store()
 * @method static \Atannex\Limiting\Stats\Recorder stats()
 * @method static \Atannex\Limiting\Limiting fake()
 * @method static array<int, \Atannex\Limiting\Verdict> verdicts(?string $policy = null)
 * @method static void assertAttempted(string $policy, ?int $times = null)
 * @method static void assertNotAttempted(string $policy)
 * @method static void assertLimited(string $policy, ?int $times = null)
 * @method static void assertNotLimited(?string $policy = null)
 * @method static void assertJailed(string $subject)
 * @method static void assertNotJailed(string $subject)
 *
 * @see \Atannex\Limiting\Limiting
 */
final class Limiting extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Atannex\Limiting\Limiting::class;
    }
}
