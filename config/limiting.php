<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Switch
    |--------------------------------------------------------------------------
    |
    | Off lets everything through (headers and events stop too). For a safe
    | rollout prefer dry_run: limits are evaluated and refusals reported
    | (WouldLimit events, logs, stats), but nothing is refused.
    |
    */

    'enabled' => env('LIMITING_ENABLED', true),

    'dry_run' => env('LIMITING_DRY_RUN', false),

    /*
    |--------------------------------------------------------------------------
    | Store
    |--------------------------------------------------------------------------
    |
    | cache: any Laravel cache store with atomic locks (redis, memcached,
    |        database, dynamodb, file). Exact, a few round trips per update.
    | redis: lock-free compare-and-set on a Redis connection. Fastest.
    | memory: this process only. Tests and per-worker limits.
    |
    | Every server must share the store, or each keeps its own counts.
    |
    */

    'store' => env('LIMITING_STORE', 'cache'),

    'stores' => [
        'cache' => [
            'driver' => 'cache',
            'store' => env('LIMITING_CACHE_STORE'),   // null: the default cache store
            'lock_wait' => 3,                         // seconds to wait for a key's lock
            'lock_seconds' => 5,                      // lock expiry, in case a process dies holding it
        ],
        'redis' => [
            'driver' => 'redis',
            'connection' => env('LIMITING_REDIS_CONNECTION', 'default'),
            'retries' => 64,                          // compare-and-set retries under contention
        ],
        'memory' => [
            'driver' => 'memory',
        ],
    ],

    'prefix' => env('LIMITING_PREFIX', 'limiting:'),

    /*
    | Subjects (IPs, e-mails, users) are HMAC-hashed with this secret before
    | they become store keys. Defaults to APP_KEY. Changing it resets every
    | counter and jail sentence.
    */
    'secret' => env('LIMITING_SECRET'),

    /*
    | system: this server's clock (follows Carbon test time).
    | store: Redis server time, so servers with drifting clocks agree. Needs
    |        the redis store.
    */
    'clock' => env('LIMITING_CLOCK', 'system'),

    /*
    | Time zone for calendar quotas (daily, monthly…) that don't name one.
    */
    'timezone' => env('LIMITING_TIMEZONE', 'UTC'),

    /*
    |--------------------------------------------------------------------------
    | Defaults for limits
    |--------------------------------------------------------------------------
    |
    | Algorithms: sliding-window (default), sliding-log, fixed-window,
    | token-bucket, leaky-bucket, gcra, quota, concurrency.
    |
    | Keys: actor (the user, or the IP for guests), ip, subnet, user, session,
    | token, fingerprint, global, route, method, path, header:Name,
    | input:field, email:field, route:parameter.
    |
    */

    'default_algorithm' => 'sliding-window',

    'default_by' => 'actor',

    /*
    | When the store fails: 'open' lets requests through (availability),
    | 'closed' refuses them (security). Each limit can override it with
    | ->failClosed() / 'fail' => 'closed'. Refusals then carry this
    | Retry-After.
    */
    'fail' => env('LIMITING_FAIL', 'open'),

    'fail_closed_retry' => 30,

    /*
    | Remember refusals in process memory until their Retry-After, so a flood
    | from one subject doesn't hit the store on every request. Only matters
    | in long-lived processes (Octane, queue workers).
    */
    'memo' => true,

    /*
    | Check programmatic blocks (Limiter::block(), upstream 429s seen by the
    | Guzzle middleware) on every limit. Costs one read per limit; turn off if
    | you never block.
    */
    'check_blocks' => true,

    'subjects' => [
        // An IPv6 customer gets a whole /64 (or more); counting single
        // addresses would let one attacker rotate through billions of them.
        'ipv4_prefix' => 32,
        'ipv6_prefix' => 64,

        // The `subnet` key: wider ranges, for spotting distributed attacks.
        'subnet_v4' => 24,
        'subnet_v6' => 48,

        // email:field keys: lowercase, NFKC, no +tags, no dots in Gmail.
        'normalize_email' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Allow and deny lists
    |--------------------------------------------------------------------------
    |
    | IPs, CIDR ranges and subject labels ('user:1'). Allowed senders skip
    | every limit (use sparingly: health checks, office ranges); denied
    | senders get deny_status everywhere the shield or a limit runs.
    |
    | IPs are what $request->ip() returns: configure trusted proxies, or
    | everyone behind your load balancer shares one address and one limit.
    |
    */

    'allow' => array_filter(explode(',', (string) env('LIMITING_ALLOW', ''))),

    'deny' => array_filter(explode(',', (string) env('LIMITING_DENY', ''))),

    'deny_status' => 403,

    /*
    |--------------------------------------------------------------------------
    | Jail
    |--------------------------------------------------------------------------
    |
    | Limits with a `jail` rule send repeat offenders here; a jailed subject
    | is refused by every policy and by the limit.shield middleware. `by` is
    | who gets jailed (normally the IP), `memory` how long earlier sentences
    | count toward escalation.
    |
    */

    'jail' => [
        'enabled' => true,
        'by' => 'ip',
        'memory' => 30 * 86400,
        'max_index' => 1000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Responses and headers
    |--------------------------------------------------------------------------
    |
    | headers.style: draft (RateLimit-Policy + RateLimit, IETF), legacy
    | (X-RateLimit-*), both, or none. Retry-After is always sent on 429.
    | jitter adds 0..n random seconds to Retry-After so refused clients don't
    | all come back in the same second.
    |
    */

    'headers' => [
        'style' => 'both',
        'jitter' => 0,
    ],

    'response' => [
        // application/problem+json (RFC 9457) for JSON requests.
        'problem_json' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Observability
    |--------------------------------------------------------------------------
    */

    'stats' => [
        'enabled' => true,
        'retention' => 48,   // hours
        'top' => 20,         // subjects kept per hour
    ],

    'log' => [
        'channel' => env('LIMITING_LOG_CHANNEL'),
        'limited' => false,  // every refusal (noisy under attack)
        'jailed' => true,
        'shadow' => true,    // refusals in shadow / dry-run mode
        'store_failures' => true,
    ],

    'middleware' => [
        'limit' => 'limit',
        'shield' => 'limit.shield',
    ],

    /*
    |--------------------------------------------------------------------------
    | Policies
    |--------------------------------------------------------------------------
    |
    | Named sets of limits, used as `limit:{name}`, #[RateLimit('{name}')] or
    | Limiting::for('{name}', $subject). A request must pass every limit of
    | its policy. Closures (for per-plan limits and the like) go in a service
    | provider: Limiting::define('api', fn (?Request $request) => ...).
    |
    */

    'policies' => [

        // A steady rate with room for bursts, per user (per IP for guests).
        'api' => [
            ['name' => 'rate', 'allow' => 60, 'per' => '1 minute', 'algorithm' => 'gcra', 'burst' => 20],
            ['name' => 'daily', 'allow' => 10000, 'per' => '1 day'],
        ],

        // Brute-force protection. Only failed attempts count, so people who
        // sign in fine are never slowed down.
        'login' => [
            // Guessing one account's password from one place.
            ['name' => 'credentials', 'allow' => 5, 'per' => '1 minute', 'by' => 'email:email|ip', 'count' => 'failures', 'algorithm' => 'sliding-log', 'block_for' => '1 minute'],
            // One account from many places (credential stuffing). Generous,
            // because anyone can spend it: it is also a way to lock a victim out.
            ['name' => 'account', 'allow' => 20, 'per' => '1 hour', 'by' => 'email:email', 'count' => 'failures'],
            // Many accounts from one place (password spraying): jail it.
            ['name' => 'address', 'allow' => 50, 'per' => '1 hour', 'by' => 'ip', 'count' => 'failures',
                'jail' => ['after' => 3, 'within' => '1 hour', 'for' => '15 minutes', 'escalate' => 2, 'max' => '1 day']],
        ],

        // Every reset sends an e-mail: count them all, and fail closed so a
        // store outage can't become a mail bomb.
        'password-reset' => [
            ['name' => 'address', 'allow' => 3, 'per' => '15 minutes', 'by' => 'email:email', 'fail' => 'closed', 'algorithm' => 'sliding-log'],
            ['name' => 'sender', 'allow' => 10, 'per' => '1 hour', 'by' => 'ip', 'fail' => 'closed'],
        ],

        // One-time codes (2FA, e-mail verification): six digits fall to
        // brute force without this.
        'one-time-code' => [
            ['name' => 'guesses', 'allow' => 5, 'per' => '10 minutes', 'by' => 'actor', 'count' => 'failures', 'fail' => 'closed', 'algorithm' => 'sliding-log', 'block_for' => '10 minutes'],
        ],

        // Expensive work: a few at a time, and not too often.
        'uploads' => [
            ['name' => 'rate', 'allow' => 20, 'per' => '1 hour', 'algorithm' => 'token-bucket', 'burst' => 5],
            ['name' => 'parallel', 'concurrent' => 2, 'lease' => '5 minutes'],
        ],

        // Forms anyone can post (contact, comments, sign-ups).
        'forms' => [
            ['name' => 'burst', 'allow' => 3, 'per' => '1 minute', 'by' => 'ip'],
            ['name' => 'hourly', 'allow' => 20, 'per' => '1 hour', 'by' => 'ip'],
        ],

        // Inbound webhooks: generous, per sending IP.
        'webhooks' => [
            ['allow' => 600, 'per' => '1 minute', 'by' => 'ip', 'algorithm' => 'fixed-window'],
        ],
    ],

];
