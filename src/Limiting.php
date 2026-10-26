<?php

declare(strict_types=1);

namespace Atannex\Limiting;

use Atannex\Limiting\Contracts\Algorithm;
use Atannex\Limiting\Contracts\Clock;
use Atannex\Limiting\Contracts\Store;
use Atannex\Limiting\Exceptions\UnknownPolicy;
use Atannex\Limiting\Http\Client\GuzzleMiddleware;
use Atannex\Limiting\Jail\Jail;
use Atannex\Limiting\Lists\AccessList;
use Atannex\Limiting\Stats\Recorder;
use Atannex\Limiting\Stores\MemoryStore;
use Atannex\Limiting\Subjects\SubjectResolver;
use Atannex\Limiting\Support\Duration;
use Atannex\Limiting\Testing\MakesAssertions;
use Closure;
use DateInterval;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The entry point (facade: Limiting).
 *
 *     Limiting::define('api', Limit::perMinute(60)->gcra(burst: 20));
 *     Limiting::for('api', $request)->attempt();
 *     Limiting::attempt('otp:'.$user->id, Limit::perMinute(3), fn () => $this->send());
 *     Limiting::jail()->lock('ip:203.0.113.9', 3600, 'abuse');
 *     Limiting::fake();
 */
final class Limiting
{
    use MakesAssertions;

    /** @var array<string, Policy> */
    private array $policies = [];

    /** @var array<string, Limit|array<mixed>|Closure> */
    private array $definitions = [];

    /** @var (Closure(Request, Verdict): Response)|null */
    private ?Closure $responder = null;

    /** @param  array<string, mixed>  $config */
    public function __construct(private readonly Container $app, private readonly array $config = [])
    {
        foreach ($config['policies'] ?? [] as $name => $definition) {
            $this->definitions[$name] = $definition;
        }
    }

    // Policies ------------------------------------------------------------

    /**
     * @param  Limit|array<int|string, Limit|array<string, mixed>>|Closure(Request|null, Context): (Limit|array<Limit>|null)  $definition
     */
    public function define(string $name, Limit|array|Closure $definition): Policy
    {
        unset($this->policies[$name]);
        $this->definitions[$name] = $definition;

        return $this->policy($name);
    }

    public function has(string $name): bool
    {
        return isset($this->definitions[$name]);
    }

    public function policy(string $name): Policy
    {
        if (isset($this->policies[$name])) {
            return $this->policies[$name];
        }

        if (! isset($this->definitions[$name])) {
            throw UnknownPolicy::named($name);
        }

        return $this->policies[$name] = new Policy($name, $this->definitions[$name]);
    }

    /** @return array<string, Policy> */
    public function policies(): array
    {
        $policies = [];

        foreach (array_keys($this->definitions) as $name) {
            $policies[$name] = $this->policy($name);
        }

        return $policies;
    }

    /**
     * A limiter for a policy and subject. The policy is a name, a Policy, or
     * limits for a one-off policy (named after `$subject`).
     *
     * @param  Request|Context|string|int|array<string, scalar>|null  $subject
     */
    public function for(string|Policy|Limit|array $policy, Request|Context|string|int|array|null $subject = null): Limiter
    {
        if (is_string($policy)) {
            $policy = $this->policy($policy);
        } elseif (! $policy instanceof Policy) {
            $policy = new Policy('adhoc', $policy);
        }

        return new Limiter($this->engine(), $policy, $subject instanceof Context ? $subject : Context::of($subject));
    }

    /** A limiter for a subject in this request's context: per-user limits outside middleware. */
    public function forRequest(string|Policy|Limit|array $policy, Request $request): Limiter
    {
        return $this->for($policy, $request);
    }

    /**
     * Laravel-style one-off: run `$callback` if `$key` is under `$limit`.
     * Returns the callback's result, or false when refused.
     *
     *     Limiting::attempt('send-code:'.$user->id, Limit::perMinute(1), fn () => $user->sendCode());
     *     Limiting::attempt('report:'.$ip, 5, fn () => ..., per: '1 hour');
     */
    public function attempt(string $key, Limit|int $limit, Closure $callback, int|float|string|DateInterval $per = 60): mixed
    {
        $limit = $limit instanceof Limit ? $limit : Limit::allow($limit)->every($per);

        return $this->for($limit, $key)->run($callback);
    }

    // Lists and jail ------------------------------------------------------

    public function jail(): Jail
    {
        return $this->app->make(Jail::class);
    }

    /** Jail a subject (an IP, 'ip:…', 'user:…') on every policy. */
    public function ban(string $subject, int|float|string|DateInterval $duration = '1 hour', string $reason = 'manual'): void
    {
        $this->jail()->lock($this->subjects()->normalizeLabel($subject), Duration::seconds($duration), $reason);
    }

    public function unban(string $subject): bool
    {
        return $this->jail()->release($this->subjects()->normalizeLabel($subject));
    }

    public function lists(): AccessList
    {
        return $this->app->make(AccessList::class);
    }

    /** Never limit these IPs, ranges or subjects. */
    public function allow(string ...$entries): self
    {
        $this->lists()->allow(...$entries);

        return $this;
    }

    /** Refuse these IPs, ranges or subjects outright. */
    public function deny(string ...$entries): self
    {
        $this->lists()->deny(...$entries);

        return $this;
    }

    /**
     * Skip every limit when `$callback` says so (health checks, staff, …).
     *
     * @param  Closure(Context): bool  $callback
     */
    public function bypass(Closure $callback): self
    {
        $this->lists()->bypass($callback);

        return $this;
    }

    // Responses -----------------------------------------------------------

    /** @param  Closure(Request, Verdict): Response  $responder */
    public function respondUsing(Closure $responder): self
    {
        $this->responder = $responder;

        return $this;
    }

    /** @return (Closure(Request, Verdict): Response)|null */
    public function responder(): ?Closure
    {
        return $this->responder;
    }

    // Integrations --------------------------------------------------------

    /**
     * Guzzle / Laravel HTTP client middleware that keeps outgoing calls under
     * a policy, and backs off for everyone when the upstream answers 429:
     *
     *     Http::withMiddleware(Limiting::guzzle('mtn-api'))->post(...);
     *
     * @param  string|int|array<string, scalar>|null  $subject
     */
    public function guzzle(string|Policy|Limit|array $policy, string|int|array|null $subject = null, int|float|string $wait = 10): GuzzleMiddleware
    {
        return new GuzzleMiddleware($this->for($policy, $subject), Duration::seconds($wait));
    }

    /** Middleware string for a policy: Limiting::middleware('api', cost: 5) is 'limit:api,5'. */
    public function middleware(string $policy, int $cost = 1): string
    {
        return 'limit:'.$policy.($cost > 1 ? ','.$cost : '');
    }

    /** @param  Algorithm|Closure(): Algorithm  $algorithm */
    public function extend(string $name, Algorithm|Closure $algorithm): self
    {
        $this->engine()->extend($name, $algorithm);

        return $this;
    }

    // Internals -----------------------------------------------------------

    public function engine(): Engine
    {
        return $this->app->make(Engine::class);
    }

    public function store(): Store
    {
        return $this->app->make(Store::class);
    }

    public function clock(): Clock
    {
        return $this->app->make(Clock::class);
    }

    public function subjects(): SubjectResolver
    {
        return $this->app->make(SubjectResolver::class);
    }

    public function stats(): Recorder
    {
        return $this->app->make(Recorder::class);
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        return $this->config;
    }

    /**
     * Swap the store for memory and record every verdict, for tests:
     *
     *     Limiting::fake();
     *     $this->post('/login', ...)->assertStatus(429);
     *     Limiting::assertLimited('login');
     */
    public function fake(): self
    {
        $this->app->instance(Store::class, new MemoryStore($this->clock()));

        foreach ([Engine::class, Jail::class, Recorder::class] as $abstract) {
            $this->app->forgetInstance($abstract);
        }

        $this->startRecording();

        return $this;
    }
}
