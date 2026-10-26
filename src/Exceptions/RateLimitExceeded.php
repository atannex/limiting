<?php

declare(strict_types=1);

namespace Atannex\Limiting\Exceptions;

use Atannex\Limiting\Verdict;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thrown when a request is over a limit or its sender is jailed.
 *
 * It extends Laravel's ThrottleRequestsException, so handlers and error pages
 * written for `throttle` keep working. JSON requests get an RFC 9457 problem
 * document; other requests fall through to the app's 429 page.
 */
class RateLimitExceeded extends ThrottleRequestsException
{
    /** @param  array<string, string>  $headers */
    public function __construct(public readonly Verdict $verdict, array $headers = [], ?string $message = null)
    {
        parent::__construct($message ?? 'Too Many Attempts.', null, $headers);
    }

    public function retryAfter(): int
    {
        return (int) ceil($this->verdict->retryAfter());
    }

    public function render(Request $request): JsonResponse|false
    {
        if (! config('limiting.response.problem_json', true) || ! $request->expectsJson()) {
            return false;
        }

        $retry = $this->retryAfter();

        return new JsonResponse([
            'type' => 'about:blank',
            'title' => 'Too Many Requests',
            'status' => 429,
            'detail' => $this->verdict->jailed()
                ? "Too many requests. Try again in {$retry} seconds."
                : "Rate limit exceeded. Try again in {$retry} seconds.",
            'retry_after' => $retry,
        ], 429, $this->getHeaders() + ['Content-Type' => 'application/problem+json']);
    }
}
