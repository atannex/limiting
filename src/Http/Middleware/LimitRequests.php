<?php

declare(strict_types=1);

namespace Atannex\Limiting\Http\Middleware;

use Atannex\Limiting\Context;
use Atannex\Limiting\Exceptions\AccessDenied;
use Atannex\Limiting\Exceptions\RateLimitExceeded;
use Atannex\Limiting\Http\Headers;
use Atannex\Limiting\Http\MiddlewareSpec;
use Atannex\Limiting\Limit;
use Atannex\Limiting\Limiting;
use Atannex\Limiting\Verdict;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The `limit` middleware.
 *
 * Limits that count every request spend their units before the controller
 * runs. Limits that count failures only (->onlyFailures(), ->countWhen())
 * refuse subjects already over the limit up front, and spend units after the
 * response, only when it matches. Concurrency slots are held while the
 * request runs.
 */
final class LimitRequests
{
    public function __construct(private readonly Limiting $limiting, private readonly Headers $headers) {}

    /** limit:api, limit:api,5 or limit:60,1 (see MiddlewareSpec). */
    public static function using(string $policy, int $cost = 1): string
    {
        return 'limit:'.$policy.($cost > 1 ? ','.$cost : '');
    }

    public function handle(Request $request, Closure $next, string ...$arguments): Response
    {
        [$policy, $cost] = MiddlewareSpec::parse($this->limiting, $arguments);
        $context = Context::fromRequest($request);
        $limits = $policy->limits($context);

        if ($limits === []) {
            return $next($request);
        }

        $engine = $this->limiting->engine();
        $upfront = array_values(array_filter($limits, static fn (Limit $limit): bool => ! $limit->countsResponses()));
        $afterwards = array_values(array_filter($limits, static fn (Limit $limit): bool => $limit->countsResponses()));

        $spent = $engine->attempt($policy->name, $upfront, $context, $cost);
        $checked = $afterwards !== [] && $spent->allowed()
            ? $engine->peek($policy->name, $afterwards, $context, $cost, penalize: true)
            : new Verdict($policy->name);
        $verdict = Verdict::merge($policy->name, $spent, $checked);

        if ($verdict->denied()) {
            $spent->release();

            return $this->refuse($request, $verdict);
        }

        if (($delay = $verdict->delay()) > 0) {
            $engine->clock()->sleep($delay);
        }

        try {
            $response = $next($request);
        } finally {
            $spent->release();
        }

        if ($afterwards !== [] && ! $verdict->bypassed()) {
            $counted = array_values(array_filter($afterwards, static fn (Limit $limit): bool => $limit->counts($response, $request)));

            if ($counted !== []) {
                $verdict = Verdict::merge($policy->name, $spent, $engine->attempt($policy->name, $counted, $context, $cost));
            }
        }

        return $this->headers->apply($response, $verdict);
    }

    private function refuse(Request $request, Verdict $verdict): Response
    {
        if ($verdict->forbidden()) {
            throw new AccessDenied($verdict, (int) config('limiting.deny_status', 403));
        }

        if ($responder = $this->limiting->responder()) {
            return $this->headers->apply($responder($request, $verdict), $verdict);
        }

        throw new RateLimitExceeded($verdict, $this->headers->for($verdict));
    }
}
