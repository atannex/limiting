<?php

declare(strict_types=1);

namespace Atannex\Limiting\Queue;

use Atannex\Limiting\Exceptions\RateLimitExceeded;
use Atannex\Limiting\Facades\Limiting;
use Closure;

/**
 * Job middleware: run the job under a policy; when refused, put it back on
 * the queue for when it would pass (or drop it, or fail it).
 *
 *     public function middleware(): array
 *     {
 *         return [new LimitJob('mtn-api', fn ($job) => 'tenant:'.$job->tenantId)];
 *     }
 */
final class LimitJob
{
    private bool $release = true;

    private bool $fail = false;

    private int $extraDelay = 0;

    /** @param  string|int|array<string, scalar>|(Closure(object): (string|int|array<string, scalar>|null))|null  $subject */
    public function __construct(
        private readonly string $policy,
        private readonly string|int|array|Closure|null $subject = null,
        private readonly int $cost = 1,
    ) {}

    /** Drop the job when refused, instead of releasing it back. */
    public function dontRelease(): self
    {
        $this->release = false;

        return $this;
    }

    /** Mark the job failed when refused. */
    public function failWhenLimited(): self
    {
        $this->fail = true;
        $this->release = false;

        return $this;
    }

    /** Add seconds to the release delay, to spread retries out. */
    public function releaseAfterExtra(int $seconds): self
    {
        $this->extraDelay = max(0, $seconds);

        return $this;
    }

    public function handle(object $job, Closure $next): mixed
    {
        $subject = $this->subject instanceof Closure ? ($this->subject)($job) : $this->subject;
        $verdict = Limiting::for($this->policy, $subject)->attempt($this->cost);

        if ($verdict->denied()) {
            $delay = max(1, (int) ceil($verdict->retryAfter())) + $this->extraDelay;

            if ($this->fail && method_exists($job, 'fail')) {
                $job->fail(new RateLimitExceeded($verdict));
            } elseif ($this->release && method_exists($job, 'release')) {
                $job->release($delay);
            }

            return null;
        }

        try {
            return $next($job);
        } finally {
            $verdict->release();
        }
    }
}
