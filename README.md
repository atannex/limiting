# atannex/limiting

[![Tests](https://github.com/atannex/limiting/actions/workflows/tests.yml/badge.svg)](https://github.com/atannex/limiting/actions/workflows/tests.yml)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php&logoColor=white)](composer.json)
[![Laravel](https://img.shields.io/badge/Laravel-11%20%7C%2012-FF2D20?logo=laravel&logoColor=white)](composer.json)
[![License: MIT](https://img.shields.io/badge/License-MIT-2ea44f.svg)](LICENSE)

Rate limiting for Laravel, built for security. Eight atomic algorithms on Redis, any cache store or memory, named policies with several limits each, brute-force protection that only counts failures, escalating jail sentences, allow and deny lists, IETF `RateLimit` headers, and limits for queue jobs and outgoing HTTP calls.

```php
// config/limiting.php ships with this policy, among others
'login' => [
    // Guessing one account's password from one place
    ['name' => 'credentials', 'allow' => 5, 'per' => '1 minute', 'by' => 'email:email|ip', 'count' => 'failures', 'block_for' => '1 minute'],
    // One account from many places (credential stuffing)
    ['name' => 'account', 'allow' => 20, 'per' => '1 hour', 'by' => 'email:email', 'count' => 'failures'],
    // Many accounts from one place (password spraying): jail it, site-wide
    ['name' => 'address', 'allow' => 50, 'per' => '1 hour', 'by' => 'ip', 'count' => 'failures',
        'jail' => ['after' => 3, 'within' => '1 hour', 'for' => '15 minutes', 'escalate' => 2, 'max' => '1 day']],
],
```

```php
Route::post('/login', LoginController::class)->middleware('limit:login');
```

People who sign in correctly are never slowed down. `Bob@Example.com`, `bob+1@example.com` and `ｂｏｂ@example.com` share one counter. An IPv6 attacker can't escape by hopping through their /64. An address that keeps hitting the wall gets jailed on every route, for longer each time.

## Why this package

|                               |                                                                                                                                                                                          |
| ----------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Exact under concurrency**   | Every update is atomic: Lua compare-and-set on Redis, a per-key lock on cache stores. The test suite races 8 processes against one limit and requires the exact count.                  |
| **Eight algorithms**          | Sliding window (default), sliding log, fixed window, token bucket, leaky bucket (shaping), GCRA, calendar quotas and concurrency, all on every store.                                   |
| **Policies, not one number**  | A policy is several limits (5/second *and* 10,000/day, per address *and* per account); a request passes only if all pass, and a refused request is refunded everywhere it was counted.  |
| **Brute-force aware**         | `count: failures` spends units only on failed responses (4xx, or a redirect back with errors) while still refusing over-limit senders before the controller runs.                     |
| **Escalating jail**           | Repeat offenders are jailed on every policy and every route covered by the shield; each sentence lasts longer.                                                                          |
| **Hard to dodge**             | IPv6 grouped per /64, e-mail and input normalization (case, `+tags`, Gmail dots, Unicode look-alikes, zero-width characters), subnet and fingerprint keys.                               |
| **Private keys**              | Subjects are HMAC-hashed with a secret before they become store keys: no IPs or e-mails in Redis, fixed-length keys, no way to craft input that collides with someone else's counter.   |
| **Fail open or closed**       | Per limit: availability for a page feed, refusal for password resets, so a Redis outage can't become a mail bomb.                                                                       |
| **Safe rollouts**             | Shadow mode per limit, or dry run for everything: refusals are reported and logged, nothing is refused.                                                                                 |
| **Standard responses**        | 429 with `Retry-After`, IETF `RateLimit-Policy` / `RateLimit` and `X-RateLimit-*` headers, RFC 9457 `application/problem+json` for API clients.                                         |
| **Beyond HTTP**               | Queue job middleware (release for later), Guzzle middleware for outgoing calls (waits its turn; backs everyone off when the upstream answers 429), `Limiting::attempt()` for anything.  |
| **Operable**                  | `limiting:doctor`, `limiting:inspect`, `limiting:jailed`, `limiting:stats` and more; events and logs for refusals, jail sentences and store failures; `Limiting::fake()` for tests.      |

## Contents

- [Installation](#installation)
- [Quick start](#quick-start)
- [Policies](#policies)
- [Limits](#limits)
- [Algorithms](#algorithms)
- [Keys: who is counted](#keys-who-is-counted)
- [Counting only failures](#counting-only-failures)
- [Cooldowns and the jail](#cooldowns-and-the-jail)
- [Allow and deny lists](#allow-and-deny-lists)
- [Middleware](#middleware)
- [Route attributes](#route-attributes)
- [In code](#in-code)
- [Queue jobs](#queue-jobs)
- [Outgoing HTTP calls](#outgoing-http-calls)
- [Responses and headers](#responses-and-headers)
- [Stores](#stores)
- [When the store fails](#when-the-store-fails)
- [Shadow mode and dry runs](#shadow-mode-and-dry-runs)
- [Events, logs and stats](#events-logs-and-stats)
- [Artisan commands](#artisan-commands)
- [Testing](#testing)
- [Security notes](#security-notes)
- [Extending](#extending)
- [Configuration reference](#configuration-reference)

## Installation

```bash
composer require atannex/limiting
php artisan vendor:publish --tag=limiting-config
php artisan limiting:doctor
```

The service provider and the `Limiting` facade register themselves. The middleware aliases `limit` and `limit.shield` are added to the router.

Requirements: PHP 8.2+, Laravel 11 or 12. For the default `cache` store, a cache with atomic locks (redis, memcached, database, dynamodb, file). For the `redis` store, phpredis or predis.

## Quick start

Limit a route with a policy from the config:

```php
Route::middleware('limit:api')->group(function () {
    Route::get('/articles', ArticleIndexController::class);
});
```

Or inline, like Laravel's `throttle`:

```php
Route::get('/search', SearchController::class)->middleware('limit:30,1');   // 30 a minute, per route
```

Put the shield in front of everything, so a jailed or deny-listed sender is refused site-wide:

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->web(prepend: ['limit.shield']);
    $middleware->api(prepend: ['limit.shield']);
})
```

Limit anything else in code:

```php
use Atannex\Limiting\Facades\Limiting;
use Atannex\Limiting\Limit;

Limiting::attempt('send-code:'.$user->id, Limit::perMinute(1), fn () => $user->sendCode())
    ?: throw ValidationException::withMessages(['code' => 'Wait a minute before asking again.']);
```

## Policies

A policy is a named list of limits. A request must pass every one; when one refuses, the units already spent on the others are refunded, so a refused request costs nothing.

**In config**, `config/limiting.php` → `policies`. The package ships with `api`, `login`, `password-reset`, `one-time-code`, `uploads`, `forms` and `webhooks`; edit or remove them freely.

```php
'policies' => [
    'api' => [
        ['name' => 'rate', 'allow' => 60, 'per' => '1 minute', 'algorithm' => 'gcra', 'burst' => 20],
        ['name' => 'daily', 'allow' => 10000, 'per' => '1 day'],
    ],
    'exports' => [
        ['name' => 'parallel', 'concurrent' => 1, 'lease' => '10 minutes'],
        ['name' => 'monthly', 'quota' => 100, 'period' => 'month', 'timezone' => 'Africa/Douala'],
    ],
],
```

**In code**, usually in a service provider's `boot()`:

```php
Limiting::define('comments', [
    Limit::perMinute(3)->by('user')->named('burst'),
    Limit::perDay(50)->by('user')->named('daily'),
]);
```

**Per request**, when limits depend on who's asking (plans, roles, API clients). Return a `Limit`, a list, `Limit::none()` or `null` (no limit):

```php
Limiting::define('api', function (?Request $request) {
    $user = $request?->user();

    return match (true) {
        $user?->isStaff() => Limit::none(),
        $user?->plan === 'partner' => Limit::perMinute(1000)->gcra(burst: 200)->by('user'),
        $user !== null => Limit::perMinute(120)->by('user'),
        default => [Limit::perMinute(30)->by('ip'), Limit::perDay(2000)->by('subnet')],
    };
});
```

A policy's counters are shared by every route that uses it. Use `->perRoute()` (inline middleware does this automatically) to count each route apart.

## Limits

```php
Limit::perSecond(10)
Limit::perMinute(60)
Limit::perHour(1000, hours: 6)                 // 1000 per 6 hours
Limit::perDay(10_000)
Limit::allow(100)->every('5 minutes')          // any duration: 90, '90s', '500ms', '2h', new DateInterval(...)
Limit::daily(500, 'Africa/Douala')             // calendar quotas, reset at local midnight
Limit::weekly(…) / Limit::monthly(…) / Limit::yearly(…)
Limit::concurrent(3, lease: '2 minutes')       // at most 3 at once
Limit::none()                                  // no limit
```

Options, all chainable:

| Method                                                             | Config key           | Does                                                                     |
| ------------------------------------------------------------------ | -------------------- | ------------------------------------------------------------------------ |
| `->by('ip')`, `->by('email:email', 'ip')`, `->by(fn ($r) => …)`    | `by`                 | Who is counted. Several keys form one combined subject. See [Keys](#keys-who-is-counted). |
| `->named('burst')`                                                 | `name`               | Name shown in headers, logs, stats. Defaults to e.g. `60-per-1m`.        |
| `->slidingWindow()` `->slidingLog()` `->fixedWindow()`             | `algorithm`          | See [Algorithms](#algorithms).                                           |
| `->tokenBucket(burst: 20)` `->gcra(burst: 20)` `->burst(20)`       | `burst`              | How many may arrive at once.                                             |
| `->leakyBucket(maxDelay: 0.5)`                                     | `max_delay`          | Space callers evenly; wait up to this long instead of refusing.          |
| `->cost(5)`, `->cost(fn (?Request $r) => …)`                        | `cost`               | Units one request spends.                                                |
| `->onlyFailures()`, `->countWhen([401, 422])`, `->countWhen(fn)`    | `count`              | Count only matching responses. See [Counting only failures](#counting-only-failures). |
| `->blockFor('15 minutes')`                                         | `block_for`          | Once refused, stay refused this long.                                    |
| `->jail(after: 3, within: '1h', for: '15m', escalate: 2, max: '1 day')` | `jail`          | Jail repeat offenders. See [the jail](#cooldowns-and-the-jail).          |
| `->failClosed()` / `->failOpen()`                                  | `fail`               | What to do when the store is down.                                       |
| `->shadow()`                                                       | `shadow`             | Report, don't refuse.                                                    |
| `->when(fn (?Request $r, Context $c) => …)`, `->unless(…)`          | -                    | Apply only sometimes.                                                    |
| `->perRoute()`                                                     | `per_route`          | Separate counters per route.                                             |

## Algorithms

| Algorithm                    | State            | Good for                                                                                    | Notes                                                                                                         |
| ---------------------------- | ---------------- | ------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------- |
| `sliding-window` *(default)* | 5 numbers        | Almost everything                                                                           | Weighted two-window counter, no burst at window edges. Tracks each window's last hit, so small limits are exact (1/minute means 60 s, not up to 120). |
| `sliding-log`                | one entry a hit  | Small, strict limits: logins, codes, password resets                                       | Exact. State grows with the limit; keep it under a few hundred.                                                |
| `fixed-window`               | 2 numbers        | Huge volumes where edges don't matter (webhooks, metrics)                                   | Cheapest. Allows up to 2× the limit across a window edge.                                                      |
| `token-bucket`               | 2 numbers        | APIs: bursts allowed, steady rate held                                                      | `burst` tokens (default: the limit), refilled at `max / window`.                                               |
| `gcra`                       | 1 timestamp      | APIs, with exact retry times                                                                | Generic cell rate algorithm (as in redis-cell). Same shape as a token bucket, less arithmetic.                 |
| `leaky-bucket`               | 1 timestamp      | Smoothing traffic to fragile upstreams, workers                                             | Shaping: callers get a `delay` to wait instead of a refusal, up to `maxDelay`. The middleware and `wait()` sleep it. |
| `quota`                      | 2 numbers        | Plans and billing: 10,000 per month                                                         | Resets on calendar boundaries in a time zone (`daily`, `weekly`, `monthly`, `yearly`).                         |
| `concurrency`                | one lease a slot | Expensive work: exports, uploads, reports                                                   | Slots are released when the request or job ends, or when the lease expires if the holder died.                |

Every algorithm runs on every store, and every one supports costs and refunds.

## Keys: who is counted

| Key                 | Counts per                                                                                       |
| ------------------- | ------------------------------------------------------------------------------------------------ |
| `actor` *(default)* | the signed-in user, else the IP                                                                  |
| `ip`                | IP address; IPv6 per /64 (`subjects.ipv6_prefix`); `::ffff:1.2.3.4` is read as `1.2.3.4`          |
| `subnet`            | IPv4 /24, IPv6 /48 (`subjects.subnet_v4/v6`): distributed attacks from one network               |
| `user`              | authenticated user id                                                                            |
| `session`           | session id (hashed)                                                                              |
| `token`             | bearer token (hashed)                                                                            |
| `fingerprint`       | hash of IP, User-Agent, Accept-Language, Accept-Encoding                                         |
| `header:X-Api-Key`  | a header's value (hashed)                                                                        |
| `input:phone`       | a request field, NFKC-normalized, lowercased, trimmed                                            |
| `email:email`       | an e-mail field, normalized: lowercase, no `+tag`, no dots for Gmail, IDN domains, look-alikes folded |
| `route:post`        | a route parameter (models by route key)                                                          |
| `route`             | the route itself (name, or method and URI)                                                       |
| `method`, `path`    | HTTP method, path                                                                                |
| `global`            | everybody together                                                                               |
| `fn (Request $r) => …` | anything you return                                                                           |

Combine keys with `|` (`'email:email|ip'`) or several arguments (`->by('email:email', 'ip')`). When a key has no value (no `email` field, a guest for `user`), it falls back to `actor`, so a missing field can't open a shared bucket or a free pass.

Outside HTTP, you name the subject: `Limiting::for('mtn-api', 'tenant:'.$tenant->id)` or `Limiting::for('sms', ['user' => 5, 'country' => 'CM'])`.

## Counting only failures

Login forms, one-time codes and password confirmations should count wrong guesses, not visits:

```php
Limit::perMinute(5)->by('email:email', 'ip')->onlyFailures()
```

With a failure-only limit, the middleware:

1. refuses the request up front if the subject is already at the limit, so a locked-out guesser can't keep trying, even with the right password;
2. lets the request run;
3. spends a unit only if the response is a failure: a 4xx or 5xx status other than 429, or a redirect carrying validation errors in the session (how Laravel's web login reports a wrong password).

`->countWhen([401, 403, 422])` or `->countWhen(fn (Response $response, Request $request) => …)` set your own rule.

## Cooldowns and the jail

`->blockFor('10 minutes')` keeps a subject refused for a while once it hits the limit, even if the window would let it back sooner.

The **jail** is for repeat offenders. A limit with `->jail(after: 3, within: '1 hour', for: '15 minutes', escalate: 2, max: '1 day')` counts each refusal as a strike. After `after` strikes within `within`, the subject is jailed:

- every policy refuses it, and so does the `limit.shield` middleware on every route it covers;
- the first sentence lasts `for`; each later sentence within `jail.memory` (30 days) lasts `escalate` times longer, up to `max`: 15 min, 30 min, 1 h, 2 h…;
- by default the subject's **IP** is jailed (config `jail.by`, or `->jail(by: 'user')`), whatever the limit counted by.

```php
Limiting::ban('203.0.113.9', '1 day', 'scraping');   // or 'ip:…', 'user:42'
Limiting::unban('203.0.113.9');
Limiting::jail()->all();                             // sentences currently running
Limiting::jail()->pardon('user:42');                 // release and forget earlier sentences
```

## Allow and deny lists

```php
// config/limiting.php, or LIMITING_ALLOW / LIMITING_DENY (comma-separated)
'allow' => ['10.0.0.0/8', 'user:1'],
'deny' => ['198.51.100.0/24', '2001:db8:bad::/48'],
```

```php
Limiting::allow('192.0.2.10');
Limiting::deny('user:666');
Limiting::bypass(fn (Context $context) => $context->request?->is('health'));
```

Allowed senders skip every limit (no headers either). Denied senders get `deny_status` (403) from the shield and from every limit. Deny wins over allow. Entries are IPs, CIDR ranges, or subject labels.

## Middleware

```text
limit:api                        a policy
limit:api,5                      a policy; this route costs 5 units
limit:60,1                       60 per 1 minute, per route (Laravel throttle syntax)
limit:5,per=10m,by=email:email|ip,count=failures,block=15m,algo=sliding-log,fail=closed,name=guess
limit.shield                     refuse jailed and deny-listed senders
```

Inline options: `per`, `by`, `algo`, `burst`, `cost`, `block`, `count` (`failures` or codes separated by `;`), `fail`, `max_delay`, `shadow`, `name`.

`Limiting::middleware('api', cost: 5)` and `LimitRequests::using('api', 5)` build the string for you.

Concurrency slots are held while the controller runs and released once it returns. Leaky-bucket delays are slept before the controller runs, so keep `maxDelay` short on web routes.

## Route attributes

With [atannex/routing](https://github.com/atannex/routing):

```php
use Atannex\Limiting\Routing\RateLimit;

#[Post('/login', name: 'login')]
#[RateLimit('login')]
public function store(LoginRequest $request) { … }

#[Get('/exports/{report}', name: 'exports.show')]
#[RateLimit('exports', cost: 5)]
#[RateLimit(10, per: '1 hour', by: 'user')]          // stacks: both must pass
public function show(Report $report) { … }

#[RateLimit(5, per: '10 minutes', by: 'email:email|ip', count: 'failures', blockFor: '15 minutes', failClosed: true)]
```

## In code

```php
$limiter = Limiting::for('login', $request);        // or a subject: 'user:42', ['tenant' => 7]

$verdict = $limiter->attempt();                     // spend one unit (or attempt(5))
$verdict->allowed();                                // bool
$verdict->retryAfter();                             // seconds, float
$verdict->remaining();                              // on the tightest limit
$verdict->jailed(); $verdict->forbidden(); $verdict->degraded(); $verdict->shadowed();
$verdict->decisions();                              // one Decision per limit
$verdict->release();                                // give back concurrency slots

$limiter->check();                                  // would it pass? spends nothing
$limiter->tooManyAttempts();
$limiter->remaining();
$limiter->availableIn();                            // whole seconds
$limiter->run(fn () => $this->export());            // result, or false when refused
$limiter->orFail(fn () => $this->export());         // or throws RateLimitExceeded (429)
$limiter->wait('10 seconds');                       // sleep until allowed (workers, CLI)
$limiter->refund();                                 // give a unit back
$limiter->block('5 minutes');                       // refuse this subject for a while
$limiter->reset();                                  // clear counters, cooldowns, strikes

Limiting::for(Limit::perHour(3), 'report:'.$post->id)->attempt();  // one-off limit
Limiting::attempt('otp:'.$user->id, 3, fn () => $user->sendOtp(), per: '15 minutes');
```

## Queue jobs

```php
use Atannex\Limiting\Queue\LimitJob;

public function middleware(): array
{
    return [
        (new LimitJob('sms-provider', fn (self $job) => 'tenant:'.$job->tenantId))
            ->releaseAfterExtra(5),   // spread retries out
    ];
}
```

A refused job is released back to the queue for when it would pass. `->dontRelease()` drops it, `->failWhenLimited()` fails it. Concurrency slots are held while the job runs.

## Outgoing HTTP calls

Stay under a provider's limits from every worker at once:

```php
Limiting::define('mtn-momo', Limit::perSecond(5)->gcra(burst: 5)->by('global'));

Http::withMiddleware(Limiting::guzzle('mtn-momo', wait: 10))->post($url, $payload);
```

Each call waits (sleeping) up to `wait` seconds for room, then throws `RateLimitExceeded`. When the upstream answers 429 or 503 with `Retry-After`, the whole policy is blocked for that long, so all your workers back off together instead of each one hitting the wall.

## Responses and headers

A refused request throws `Atannex\Limiting\Exceptions\RateLimitExceeded`, which extends Laravel's `ThrottleRequestsException`: your 429 page and existing handlers keep working. JSON requests get an RFC 9457 problem document:

```json
{"type": "about:blank", "title": "Too Many Requests", "status": 429, "detail": "Rate limit exceeded. Try again in 42 seconds.", "retry_after": 42}
```

Headers (`headers.style`: `draft`, `legacy`, `both` (default), `none`):

```http
RateLimit-Policy: "api.rate";q=60;w=60, "api.daily";q=10000;w=86400
RateLimit: "api.rate";r=41;t=19
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 41
X-RateLimit-Reset: 1761480019
Retry-After: 19
```

`RateLimit` reports the limit closest to running out (or the one that refused). `Retry-After` is rounded up, plus optional random `headers.jitter` seconds so refused clients don't all come back at once.

For your own response:

```php
Limiting::respondUsing(fn (Request $request, Verdict $verdict) => response()->view('errors.slow-down', ['seconds' => ceil($verdict->retryAfter())], 429));
```

## Stores

| `LIMITING_STORE` | How                                                                                                     | Use                                                    |
| ---------------- | ------------------------------------------------------------------------------------------------------- | ------------------------------------------------------ |
| `cache` *(default)* | Any Laravel cache store with atomic locks; a short lock per update (`LIMITING_CACHE_STORE` picks one) | Works everywhere: redis, memcached, database, dynamodb, file |
| `redis`          | Lock-free: read, compute, write with a Lua compare-and-set; retry on conflict. One key per update, so Redis Cluster works | Production with Redis: fastest                         |
| `memory`         | This process only                                                                                       | Tests, per-worker limits. Never for web under php-fpm  |

All servers must share the store. `LIMITING_CLOCK=store` uses Redis server time, so servers whose clocks drift still agree on windows. Add a store with `app(StoreFactory::class)->extend('dynamo', fn (array $config, $app) => new MyStore(...))`.

## When the store fails

Per limit, or `LIMITING_FAIL` for all:

- **open** (default): the request goes through, flagged `degraded`. Good for read traffic: an outage shouldn't take the site down.
- **closed**: the request is refused with `Retry-After: fail_closed_retry` (30). Use it where abuse costs money or safety: password resets, SMS, payments, code checks.

Either way a `StoreFailed` event is dispatched and logged.

## Shadow mode and dry runs

Roll a new limit out without risk:

```php
Limit::perMinute(30)->shadow()           // this limit reports only
```

```bash
LIMITING_DRY_RUN=true                    # every limit reports only
```

Would-be refusals dispatch `WouldLimit`, are logged at `info`, and show in `limiting:stats` as `shadow`. Nothing is refused.

## Events, logs and stats

| Event         | When                                                         |
| ------------- | ------------------------------------------------------------ |
| `Limited`     | a limit refused (decision + context)                         |
| `WouldLimit`  | a shadow limit would have refused                            |
| `Jailed`      | a subject was jailed (subject, until, seconds, sentence)     |
| `Released`    | a subject was let out early                                  |
| `StoreFailed` | the store failed; says whether the limit failed open or closed |

Logging is configured under `log` (channel, and which of these to log). `limiting:stats` shows refusals per limit and the subjects refused most, per hour, for the last 48 hours; only refusals are recorded, so normal traffic costs nothing.

## Artisan commands

| Command                                         | Does                                                                                               |
| ----------------------------------------------- | -------------------------------------------------------------------------------------------------- |
| `limiting:doctor`                               | Checks the setup: store shared and atomic, round-trip timing, secret, trusted proxies, clock skew, aliases, policies. Exits non-zero on problems; run it in deploys. |
| `limiting:policies`                             | Lists policies and limits                                                                          |
| `limiting:inspect login "email:bob@example.com\|ip:203.0.113.9"` | Where a subject stands on each limit, without spending anything               |
| `limiting:reset login ip:203.0.113.9`           | Clears its counters, cooldowns and strikes                                                         |
| `limiting:jail 203.0.113.9 --for=1d --reason=…` | Jails a subject                                                                                    |
| `limiting:release 203.0.113.9 [--pardon]`       | Lets it out (and forgets its history)                                                              |
| `limiting:jailed`                               | Current sentences                                                                                  |
| `limiting:stats --hours=24`                     | Refusals and top subjects                                                                          |

Subjects typed on the command line are normalized like requests are: `2001:db8:1:2::7` becomes `ip:2001:db8:1:2::/64`.

## Testing

```php
use Atannex\Limiting\Facades\Limiting;

public function test_login_is_rate_limited(): void
{
    Limiting::fake();                    // memory store, records every verdict

    foreach (range(1, 5) as $i) {
        $this->post('/login', ['email' => 'bob@example.com', 'password' => 'wrong']);
    }

    $this->post('/login', ['email' => 'bob@example.com', 'password' => 'right'])->assertStatus(429);

    Limiting::assertLimited('login', 1);     // the sixth request
    Limiting::assertAttempted('login', 5);   // units spent: the five failures
    Limiting::assertNotJailed('127.0.0.1');
}
```

The clock follows Carbon, so `$this->travel(2)->minutes()` moves windows along. Also: `assertNotLimited()`, `assertNotAttempted()`, `assertJailed()`, `Limiting::verdicts('login')`.

## Security notes

- **Trusted proxies.** Limits use `$request->ip()`. Behind a load balancer or CDN, configure trusted proxies, or every visitor shares one address and one limit. Trust only proxies that overwrite `X-Forwarded-For`, or attackers pick their own IP. `limiting:doctor` reports what is set.
- **IPv6.** Counting single IPv6 addresses is useless: one customer has 2⁶⁴ of them. The default groups per /64; some providers hand out /56 or /48. Raise `ipv6_prefix` if you see rotation, or add a `subnet` limit.
- **Lockouts are a weapon too.** A per-account limit (`email:email` alone) can be used to lock a victim out. Keep per-account limits generous, pair them with tighter per-address-and-IP limits, and offer another way in (an e-mailed link).
- **Fail closed where abuse costs money.** Password resets, SMS, verification codes, payment attempts.
- **Secrets.** Keys are `HMAC-SHA256(LIMITING_SECRET or APP_KEY, policy, limit, subject)`. Rotating the secret resets all counters and sentences.
- **What is stored readable.** Counters hold only hashes. The jail list and hourly stats keep subject labels (`ip:…`, `email:…`) readable, because that is what you need to see who was jailed and who is attacking. Tokens, sessions and header values are hashed even there. Turn `stats.enabled` off if labels must not be kept at all.
- **Bot farms.** Rate limits slow automated abuse; they don't identify bots. Combine with CAPTCHAs or proof-of-work on forms that see distributed attacks.

## Extending

A custom algorithm is a pure function of the stored state:

```php
use Atannex\Limiting\Contracts\Algorithm;
use Atannex\Limiting\Algorithms\{Outcome, Transition};

final class MyAlgorithm implements Algorithm
{
    public function attempt(?array $state, Limit $limit, float $now, int $cost): Transition { … }
    public function refund(?array $state, Limit $limit, float $now, int $cost, ?string $lease = null): ?Transition { … }
}

Limiting::extend('mine', new MyAlgorithm);
Limit::perMinute(10)->using('mine');
```

It runs inside the store's atomic section on every store. The callback may run more than once (the Redis store retries on conflict), so keep it free of side effects.

## Configuration reference

| Key                          | Default          | Meaning                                                              |
| ---------------------------- | ---------------- | -------------------------------------------------------------------- |
| `enabled`                    | `true`           | Off lets everything through                                          |
| `dry_run`                    | `false`          | Evaluate and report, refuse nothing                                  |
| `store`, `stores.*`          | `cache`          | See [Stores](#stores)                                                |
| `prefix`                     | `limiting:`      | Store key prefix                                                     |
| `secret`                     | APP_KEY          | HMAC secret for keys                                                 |
| `clock`                      | `system`         | `store` for Redis server time                                        |
| `timezone`                   | `UTC`            | For calendar quotas without one                                      |
| `default_algorithm`          | `sliding-window` |                                                                      |
| `default_by`                 | `actor`          |                                                                      |
| `fail`, `fail_closed_retry`  | `open`, `30`     | See [When the store fails](#when-the-store-fails)                    |
| `memo`                       | `true`           | Remember refusals in long-lived processes until their Retry-After    |
| `check_blocks`               | `true`           | Honour programmatic blocks and upstream 429s on every limit          |
| `subjects.*`                 |                  | IP prefixes, subnet sizes, e-mail normalization                      |
| `allow`, `deny`, `deny_status` | `[]`, `[]`, `403` | See [lists](#allow-and-deny-lists)                               |
| `jail.*`                     |                  | `enabled`, `by` (`ip`), `memory` (30 days), `max_index`              |
| `headers.style`, `headers.jitter` | `both`, `0` |                                                                      |
| `response.problem_json`      | `true`           | RFC 9457 JSON for API clients                                        |
| `stats.*`                    |                  | `enabled`, `retention` hours, `top` subjects                         |
| `log.*`                      |                  | `channel`, and booleans for `limited`, `jailed`, `shadow`, `store_failures` |
| `middleware.limit`, `middleware.shield` | `limit`, `limit.shield` | Alias names                                       |
| `policies`                   | presets          | See [Policies](#policies)                                            |

## License

MIT. See [LICENSE](LICENSE).
