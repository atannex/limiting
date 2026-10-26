<?php

declare(strict_types=1);

namespace Atannex\Limiting\Http;

use Atannex\Limiting\Decision;
use Atannex\Limiting\Verdict;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rate limit headers, in the IETF draft form (RateLimit-Policy, RateLimit),
 * the long-standing X-RateLimit-* form, or both. Retry-After goes on every
 * refusal, rounded up so clients never retry early.
 */
final class Headers
{
    public function __construct(
        private readonly string $style = 'both',
        private readonly int $jitter = 0,
        private readonly ?\Closure $now = null,
    ) {}

    /** @return array<string, string> */
    public function for(Verdict $verdict): array
    {
        $headers = [];

        if ($verdict->denied()) {
            $headers['Retry-After'] = (string) (max(1, (int) ceil($verdict->retryAfter())) + ($this->jitter > 0 ? random_int(0, $this->jitter) : 0));
        }

        if ($this->style === 'none' || $verdict->bypassed()) {
            return $headers;
        }

        $tightest = $verdict->tightest();

        if ($tightest === null || ! $tightest->measured()) {
            return $headers;
        }

        if ($this->style === 'draft' || $this->style === 'both') {
            $policies = [];

            foreach ($verdict->decisions() as $decision) {
                if ($decision->measured()) {
                    $policies[] = $this->item($decision).';q='.$decision->max.';w='.max(1, (int) ceil($decision->window));
                }
            }

            $headers['RateLimit-Policy'] = implode(', ', $policies);
            $headers['RateLimit'] = $this->item($tightest).';r='.max(0, $tightest->remaining).';t='.$this->seconds($tightest);
        }

        if ($this->style === 'legacy' || $this->style === 'both') {
            $headers['X-RateLimit-Limit'] = (string) $tightest->max;
            $headers['X-RateLimit-Remaining'] = (string) max(0, $tightest->remaining);
            $headers['X-RateLimit-Reset'] = (string) ((int) ($this->now !== null ? ($this->now)() : time()) + $this->seconds($tightest));
        }

        return $headers;
    }

    public function apply(Response $response, Verdict $verdict): Response
    {
        foreach ($this->for($verdict) as $name => $value) {
            $response->headers->set($name, $value, true);
        }

        return $response;
    }

    private function item(Decision $decision): string
    {
        return '"'.addcslashes($decision->policy.'.'.$decision->limit, '"\\').'"';
    }

    private function seconds(Decision $decision): int
    {
        return max(0, (int) ceil($decision->denied() ? $decision->retryAfter : $decision->resetAfter));
    }
}
