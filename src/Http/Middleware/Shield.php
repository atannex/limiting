<?php

declare(strict_types=1);

namespace Atannex\Limiting\Http\Middleware;

use Atannex\Limiting\Context;
use Atannex\Limiting\Decision;
use Atannex\Limiting\Exceptions\AccessDenied;
use Atannex\Limiting\Exceptions\RateLimitExceeded;
use Atannex\Limiting\Http\Headers;
use Atannex\Limiting\Limiting;
use Atannex\Limiting\Verdict;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The `limit.shield` middleware: refuses deny-listed and jailed senders on
 * every route it covers, before anything else runs. Put it in the web and
 * api groups so a jail sentence applies site-wide, not just on the policy
 * that issued it.
 */
final class Shield
{
    public function __construct(private readonly Limiting $limiting, private readonly Headers $headers) {}

    public function handle(Request $request, Closure $next): Response
    {
        $context = Context::fromRequest($request);
        $lists = $this->limiting->lists();

        if ($lists->denies($context)) {
            throw new AccessDenied(new Verdict('shield', [new Decision('shield', 'deny-list', false, 0, 0, 0, 0, 0, 'deny-list', reason: Decision::DENIED)]), (int) config('limiting.deny_status', 403));
        }

        if ($lists->allows($context) || ! $this->limiting->jail()->enabled()) {
            return $next($request);
        }

        $jail = $this->limiting->jail();

        foreach ($this->limiting->subjects()->identities($context) as $label) {
            try {
                $remaining = $jail->remaining($label);
            } catch (\Throwable) {
                break;
            }

            if ($remaining > 0) {
                $verdict = new Verdict('shield', [new Decision('shield', 'jail', false, 0, 0, $remaining, $remaining, $remaining, $label, reason: Decision::JAILED)]);

                if ($responder = $this->limiting->responder()) {
                    return $this->headers->apply($responder($request, $verdict), $verdict);
                }

                throw new RateLimitExceeded($verdict, $this->headers->for($verdict));
            }
        }

        return $next($request);
    }
}
