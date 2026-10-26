<?php

declare(strict_types=1);

namespace Atannex\Limiting\Http\Client;

use Atannex\Limiting\Limiter;
use Closure;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Keeps outgoing calls under a policy. Before each call it waits (up to
 * `$wait` seconds) for room, then throws RateLimitExceeded. When the
 * upstream answers 429 or 503 with Retry-After, it blocks the policy for that
 * long, so every worker backs off at once instead of each finding out alone.
 *
 *     Http::withMiddleware(Limiting::guzzle('mtn-api'))->post(...);
 *     $stack->push(Limiting::guzzle('stripe', wait: 30));
 */
final class GuzzleMiddleware
{
    public function __construct(private readonly Limiter $limiter, private readonly float $wait = 10.0) {}

    public function __invoke(callable $handler): Closure
    {
        return function (RequestInterface $request, array $options) use ($handler) {
            $verdict = $this->limiter->wait($this->wait);

            try {
                $promise = $handler($request, $options);
            } catch (\Throwable $e) {
                $verdict->release();

                throw $e;
            }

            return $promise->then(
                function (ResponseInterface $response) use ($verdict): ResponseInterface {
                    $verdict->release();

                    if (in_array($response->getStatusCode(), [429, 503], true) && ($seconds = $this->retryAfter($response)) > 0) {
                        $this->limiter->block($seconds);
                    }

                    return $response;
                },
                function ($reason) use ($verdict) {
                    $verdict->release();

                    throw $reason instanceof \Throwable ? $reason : new \RuntimeException((string) $reason);
                },
            );
        };
    }

    private function retryAfter(ResponseInterface $response): float
    {
        $header = trim($response->getHeaderLine('Retry-After'));

        if ($header === '') {
            return 0.0;
        }

        if (is_numeric($header)) {
            return min(3600.0, (float) $header);
        }

        $at = strtotime($header);

        return $at === false ? 0.0 : min(3600.0, max(0.0, $at - time()));
    }
}
