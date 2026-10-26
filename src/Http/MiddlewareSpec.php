<?php

declare(strict_types=1);

namespace Atannex\Limiting\Http;

use Atannex\Limiting\Exceptions\InvalidLimit;
use Atannex\Limiting\Limit;
use Atannex\Limiting\Limiting;
use Atannex\Limiting\Policy;

/**
 * Reads middleware arguments:
 *
 *     limit:api                     a named policy
 *     limit:api,5                   ...where this route costs 5 units
 *     limit:api,cost=5
 *     limit:60,1                    60 a minute, per route (like throttle:60,1)
 *     limit:5,per=10m,by=email:email|ip,count=failures,block=15m,algo=sliding-log,fail=closed
 */
final class MiddlewareSpec
{
    /** @var array<string, Policy> */
    private static array $inline = [];

    /**
     * @param  list<string>  $arguments
     * @return array{0: Policy, 1: int}
     */
    public static function parse(Limiting $limiting, array $arguments): array
    {
        if ($arguments === []) {
            throw new InvalidLimit('The limit middleware needs a policy name or a number, as in limit:api or limit:60,1.');
        }

        $first = array_shift($arguments);
        [$positional, $options] = self::split($arguments);

        if (! ctype_digit($first)) {
            return [$limiting->policy($first), (int) ($options['cost'] ?? $positional[0] ?? 1)];
        }

        $signature = $first.','.implode(',', $arguments);

        if (isset(self::$inline[$signature])) {
            return [self::$inline[$signature], (int) ($options['cost'] ?? 1)];
        }

        $limit = Limit::allow((int) $first)->every($options['per'] ?? (isset($positional[0]) ? ((float) $positional[0]) * 60 : 60))->perRoute();
        $config = array_filter([
            'algorithm' => $options['algo'] ?? $options['algorithm'] ?? null,
            'burst' => $options['burst'] ?? null,
            'by' => $options['by'] ?? null,
            'block_for' => $options['block'] ?? $options['block_for'] ?? null,
            'count' => $options['count'] ?? null,
            'fail' => $options['fail'] ?? null,
            'name' => $options['name'] ?? null,
            'max_delay' => $options['max_delay'] ?? null,
        ], static fn ($value) => $value !== null);

        if (isset($config['algorithm'])) {
            $limit->using($config['algorithm']);
        }

        if (isset($config['burst'])) {
            $limit->burst((int) $config['burst']);
        }

        if (isset($config['by'])) {
            $limit->by($config['by']);
        }

        if (isset($config['block_for'])) {
            $limit->blockFor($config['block_for']);
        }

        if (isset($config['count']) && $config['count'] !== 'all') {
            $limit->countWhen($config['count']);
        }

        if (isset($config['fail'])) {
            $config['fail'] === 'closed' ? $limit->failClosed() : $limit->failOpen();
        }

        if (isset($config['max_delay'])) {
            $limit->leakyBucket($config['max_delay']);
        }

        if (isset($options['shadow'])) {
            $limit->shadow();
        }

        $limit->named($config['name'] ?? 'inline');

        return [self::$inline[$signature] = new Policy('inline:'.substr(md5($signature), 0, 10), [$limit]), (int) ($options['cost'] ?? 1)];
    }

    /**
     * @param  list<string>  $arguments
     * @return array{0: list<string>, 1: array<string, string>}
     */
    private static function split(array $arguments): array
    {
        $positional = [];
        $options = [];

        foreach ($arguments as $argument) {
            if (str_contains($argument, '=')) {
                [$key, $value] = explode('=', $argument, 2);
                $options[trim($key)] = trim($value);
            } elseif (trim($argument) !== '') {
                $positional[] = trim($argument);
            }
        }

        return [$positional, $options];
    }
}
