<?php

declare(strict_types=1);

namespace Atannex\Limiting;

use Atannex\Limiting\Exceptions\InvalidLimit;
use Atannex\Limiting\Support\Duration;
use Closure;
use DateInterval;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * One limit: how many units, over what time, counted by which algorithm,
 * per which subject.
 *
 *     Limit::perMinute(60)                                   // 60 a minute per user, or per IP for guests
 *     Limit::perMinute(5)->by('email:email', 'ip')           // per address and IP pair
 *     Limit::allow(100)->every('5 minutes')->gcra(burst: 20) // smooth rate with a burst
 *     Limit::perMinute(5)->onlyFailures()->jail(after: 3)    // brute-force protection
 *     Limit::concurrent(2)->by('user')                       // two at a time
 *     Limit::monthly(10_000, 'Africa/Douala')                // calendar quota
 */
final class Limit
{
    public const FIXED_WINDOW = 'fixed-window';

    public const SLIDING_WINDOW = 'sliding-window';

    public const SLIDING_LOG = 'sliding-log';

    public const TOKEN_BUCKET = 'token-bucket';

    public const LEAKY_BUCKET = 'leaky-bucket';

    public const GCRA = 'gcra';

    public const QUOTA = 'quota';

    public const CONCURRENCY = 'concurrency';

    public ?string $name = null;

    public ?string $algorithm = null;

    public ?int $burst = null;

    /** @var list<string|Closure> */
    public array $by = [];

    public int|Closure $cost = 1;

    public ?float $blockFor = null;

    /** @var (Closure(Response, Request): bool)|null */
    public ?Closure $countWhen = null;

    /** 'open', 'closed' or null for the configured default. */
    public ?string $fail = null;

    public bool $shadow = false;

    /** @var array{after: int, within: float, for: float, escalate: float, max: float, by: string|null}|null */
    public ?array $jail = null;

    /** @var list<Closure> */
    public array $conditions = [];

    public bool $perRoute = false;

    /** Longest a leaky bucket makes a caller wait. */
    public float $maxDelay = 1.0;

    /** How long a concurrency slot is held at most, in case its holder dies. */
    public float $lease = 60.0;

    /** Calendar period for quotas: day, week, month or year. */
    public ?string $period = null;

    public ?string $timezone = null;

    public bool $unlimited = false;

    public function __construct(public int $max, public float $window)
    {
        if ($max < 0) {
            throw new InvalidLimit('A limit must allow zero or more units.');
        }

        if ($window <= 0) {
            throw new InvalidLimit('A limit window must be longer than zero.');
        }
    }

    // Construction --------------------------------------------------------

    public static function allow(int $max): self
    {
        return new self($max, 60);
    }

    public function every(int|float|string|DateInterval $duration): self
    {
        $this->window = Duration::seconds($duration);

        return $this;
    }

    /** Alias of every(). */
    public function per(int|float|string|DateInterval $duration): self
    {
        return $this->every($duration);
    }

    public static function perSecond(int $max, int|float $seconds = 1): self
    {
        return new self($max, (float) $seconds);
    }

    public static function perMinute(int $max, int|float $minutes = 1): self
    {
        return new self($max, $minutes * 60.0);
    }

    public static function perHour(int $max, int|float $hours = 1): self
    {
        return new self($max, $hours * 3600.0);
    }

    public static function perDay(int $max, int|float $days = 1): self
    {
        return new self($max, $days * 86400.0);
    }

    /** Resets at midnight in `$timezone`, not 24 hours after the first hit. */
    public static function daily(int $max, ?string $timezone = null): self
    {
        return self::calendar($max, 'day', $timezone);
    }

    public static function weekly(int $max, ?string $timezone = null): self
    {
        return self::calendar($max, 'week', $timezone);
    }

    public static function monthly(int $max, ?string $timezone = null): self
    {
        return self::calendar($max, 'month', $timezone);
    }

    public static function yearly(int $max, ?string $timezone = null): self
    {
        return self::calendar($max, 'year', $timezone);
    }

    /** At most `$max` at the same time; slots are given back when the work ends. */
    public static function concurrent(int $max, int|float|string $lease = 60): self
    {
        $limit = new self($max, Duration::seconds($lease));
        $limit->algorithm = self::CONCURRENCY;
        $limit->lease = $limit->window;

        return $limit;
    }

    /** No limit, e.g. for staff in a policy closure. */
    public static function none(): self
    {
        $limit = new self(PHP_INT_MAX, 60);
        $limit->unlimited = true;

        return $limit;
    }

    private static function calendar(int $max, string $period, ?string $timezone): self
    {
        $limit = new self($max, ['day' => 86400, 'week' => 604800, 'month' => 2678400, 'year' => 31622400][$period]);
        $limit->algorithm = self::QUOTA;
        $limit->period = $period;
        $limit->timezone = $timezone;

        return $limit;
    }

    /**
     * The config form:
     *
     *     ['allow' => 5, 'per' => '1 minute', 'by' => 'email:email|ip', 'count' => 'failures',
     *      'algorithm' => 'gcra', 'burst' => 10, 'cost' => 1, 'block_for' => '5 minutes',
     *      'fail' => 'closed', 'shadow' => false, 'name' => 'credentials',
     *      'jail' => ['after' => 3, 'within' => '1 hour', 'for' => '15 minutes', 'escalate' => 2, 'max' => '1 day']]
     *     ['concurrent' => 2, 'lease' => '2 minutes', 'by' => 'user']
     *     ['quota' => 10000, 'period' => 'month', 'timezone' => 'Africa/Douala']
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        if (isset($config['concurrent'])) {
            $limit = self::concurrent((int) $config['concurrent'], $config['lease'] ?? 60);
        } elseif (isset($config['quota'])) {
            $period = $config['period'] ?? 'month';

            if (! in_array($period, ['day', 'week', 'month', 'year'], true)) {
                throw new InvalidLimit("Unknown quota period \"{$period}\". Use day, week, month or year.");
            }

            $limit = self::calendar((int) $config['quota'], $period, $config['timezone'] ?? null);
        } elseif (isset($config['allow'])) {
            $limit = self::allow((int) $config['allow'])->every($config['per'] ?? 60);
        } else {
            throw new InvalidLimit('A limit needs `allow`, `concurrent` or `quota`.');
        }

        if (isset($config['name'])) {
            $limit->named((string) $config['name']);
        }

        if (isset($config['algorithm'])) {
            $limit->using((string) $config['algorithm']);
        }

        if (isset($config['burst'])) {
            $limit->burst((int) $config['burst']);
        }

        if (isset($config['max_delay'])) {
            $limit->maxDelay = Duration::seconds($config['max_delay']);
        }

        if (isset($config['by'])) {
            $limit->by(...(array) $config['by']);
        }

        if (isset($config['cost'])) {
            $limit->cost((int) $config['cost']);
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

        if (! empty($config['shadow'])) {
            $limit->shadow();
        }

        if (! empty($config['per_route'])) {
            $limit->perRoute();
        }

        if (isset($config['jail'])) {
            $jail = (array) $config['jail'];
            $limit->jail(
                after: (int) ($jail['after'] ?? 5),
                within: $jail['within'] ?? '1 hour',
                for: $jail['for'] ?? '15 minutes',
                escalate: (float) ($jail['escalate'] ?? 2.0),
                max: $jail['max'] ?? '1 week',
                by: $jail['by'] ?? null,
            );
        }

        return $limit;
    }

    // Options -------------------------------------------------------------

    public function named(string $name): self
    {
        if (! preg_match('/^[A-Za-z0-9._\-]+$/', $name)) {
            throw new InvalidLimit("Limit names use letters, digits, dots, dashes and underscores; \"{$name}\" doesn't.");
        }

        $this->name = $name;

        return $this;
    }

    public function using(string $algorithm): self
    {
        $this->algorithm = $algorithm;

        return $this;
    }

    public function fixedWindow(): self
    {
        return $this->using(self::FIXED_WINDOW);
    }

    public function slidingWindow(): self
    {
        return $this->using(self::SLIDING_WINDOW);
    }

    /** Exact, but keeps one entry per hit: for small limits. */
    public function slidingLog(): self
    {
        return $this->using(self::SLIDING_LOG);
    }

    public function tokenBucket(?int $burst = null): self
    {
        $this->burst = $burst ?? $this->burst;

        return $this->using(self::TOKEN_BUCKET);
    }

    /** Spaces callers evenly: each waits its turn, for up to `$maxDelay`. */
    public function leakyBucket(int|float|string $maxDelay = 1.0): self
    {
        $this->maxDelay = Duration::seconds($maxDelay);

        return $this->using(self::LEAKY_BUCKET);
    }

    public function gcra(?int $burst = null): self
    {
        $this->burst = $burst ?? $this->burst;

        return $this->using(self::GCRA);
    }

    public function burst(int $burst): self
    {
        if ($burst < 1) {
            throw new InvalidLimit('A burst must be at least 1.');
        }

        $this->burst = $burst;

        return $this;
    }

    /**
     * What to count by. Each argument is a key: ip, subnet, user, actor
     * (user, else IP), session, token, fingerprint, global, route, method,
     * path, header:Name, input:field, email:field, route:parameter, or a
     * closure taking the request. Several arguments make a combined key.
     */
    public function by(string|Closure ...$keys): self
    {
        foreach ($keys as $key) {
            foreach (is_string($key) ? explode('|', $key) : [$key] as $part) {
                if (is_string($part) && ! Subjects\SubjectResolver::isKnown($part = trim($part))) {
                    throw new InvalidLimit("Unknown key \"{$part}\". Use ip, subnet, user, actor, session, token, fingerprint, global, route, method, path, header:Name, input:field, email:field or route:parameter.");
                }

                $this->by[] = $part;
            }
        }

        return $this;
    }

    /** Units one request spends: a number, or a closure taking the request. */
    public function cost(int|Closure $cost): self
    {
        if (is_int($cost) && $cost < 1) {
            throw new InvalidLimit('A cost must be at least 1.');
        }

        $this->cost = $cost;

        return $this;
    }

    /** Once over the limit, stay refused for this long. */
    public function blockFor(int|float|string|DateInterval $duration): self
    {
        $this->blockFor = Duration::seconds($duration);

        return $this;
    }

    /**
     * Only count requests whose response matches: 'failures', status codes,
     * or a closure taking the response and the request. A subject that is
     * already over the limit is refused before the request runs.
     *
     * @param  string|int|list<int>|Closure(Response, Request): bool  $condition
     */
    public function countWhen(string|int|array|Closure $condition): self
    {
        if ($condition === 'failures') {
            $condition = static fn (Response $response, Request $request): bool => self::isFailure($response, $request);
        } elseif (is_string($condition) || is_int($condition) || is_array($condition)) {
            $codes = array_map('intval', is_array($condition) ? $condition : preg_split('/[;\s]+/', (string) $condition));
            $condition = static fn (Response $response): bool => in_array($response->getStatusCode(), $codes, true);
        }

        $this->countWhen = $condition;

        return $this;
    }

    /** Count failed attempts only: logins, code checks, password confirmations. */
    public function onlyFailures(): self
    {
        return $this->countWhen('failures');
    }

    /** Refuse requests when the store can't be reached. */
    public function failClosed(): self
    {
        $this->fail = 'closed';

        return $this;
    }

    /** Let requests through when the store can't be reached. */
    public function failOpen(): self
    {
        $this->fail = 'open';

        return $this;
    }

    /** Report what it would refuse, refuse nothing: for rolling a limit out. */
    public function shadow(bool $shadow = true): self
    {
        $this->shadow = $shadow;

        return $this;
    }

    /**
     * Jail a subject that keeps hitting this limit: after `$after` refusals
     * within `$within`, every policy refuses it for `$for`, multiplied by
     * `$escalate` for each earlier sentence, up to `$max`. `$by` picks who is
     * jailed (default: the configured jail.by, normally the IP).
     */
    public function jail(
        int $after = 5,
        int|float|string|DateInterval $within = '1 hour',
        int|float|string|DateInterval $for = '15 minutes',
        float $escalate = 2.0,
        int|float|string|DateInterval $max = '1 week',
        ?string $by = null,
    ): self {
        if ($after < 1 || $escalate < 1) {
            throw new InvalidLimit('A jail needs `after` of at least 1 and `escalate` of at least 1.');
        }

        $this->jail = [
            'after' => $after,
            'within' => Duration::seconds($within),
            'for' => Duration::seconds($for),
            'escalate' => $escalate,
            'max' => Duration::seconds($max),
            'by' => $by,
        ];

        return $this;
    }

    /** @param  Closure(Request|null, Context): bool  $condition */
    public function when(Closure $condition): self
    {
        $this->conditions[] = $condition;

        return $this;
    }

    /** @param  Closure(Request|null, Context): bool  $condition */
    public function unless(Closure $condition): self
    {
        $this->conditions[] = static fn (?Request $request, Context $context): bool => ! $condition($request, $context);

        return $this;
    }

    /** Keep a separate counter for each route. */
    public function perRoute(bool $perRoute = true): self
    {
        $this->perRoute = $perRoute;

        return $this;
    }

    // Reading -------------------------------------------------------------

    public function algorithmOr(string $default): string
    {
        return $this->algorithm ?? $default;
    }

    public function countsResponses(): bool
    {
        return $this->countWhen !== null;
    }

    public function appliesTo(Context $context): bool
    {
        foreach ($this->conditions as $condition) {
            if (! $condition($context->request, $context)) {
                return false;
            }
        }

        return true;
    }

    public function costFor(Context $context): int
    {
        $cost = $this->cost instanceof Closure ? (int) ($this->cost)($context->request, $context) : $this->cost;

        return max(1, $cost);
    }

    public function counts(Response $response, Request $request): bool
    {
        return $this->countWhen === null || (bool) ($this->countWhen)($response, $request);
    }

    /** "60/1m", "5/1m", "2@once": a readable summary. */
    public function describe(): string
    {
        if ($this->unlimited) {
            return 'unlimited';
        }

        if ($this->algorithm === self::CONCURRENCY) {
            return "{$this->max} at once";
        }

        if ($this->algorithm === self::QUOTA) {
            return "{$this->max}/{$this->period}";
        }

        return $this->max.'/'.Duration::label($this->window);
    }

    /** Failed attempts: 4xx and 5xx (but not 429), and redirects back with errors. */
    public static function isFailure(Response $response, Request $request): bool
    {
        $status = $response->getStatusCode();

        if ($status >= 400) {
            return $status !== 429;
        }

        if ($response instanceof RedirectResponse || $response->isRedirection()) {
            $session = $request->hasSession() ? $request->session() : null;
            $errors = $session?->get('errors');

            return $errors !== null && method_exists($errors, 'any') ? $errors->any() : $errors !== null;
        }

        return false;
    }
}
