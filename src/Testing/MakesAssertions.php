<?php

declare(strict_types=1);

namespace Atannex\Limiting\Testing;

use Atannex\Limiting\Context;
use Atannex\Limiting\Limiting;
use Atannex\Limiting\Verdict;
use PHPUnit\Framework\Assert;

/**
 * Assertions for Limiting::fake().
 *
 * @mixin Limiting
 */
trait MakesAssertions
{
    /** @var list<array{verdict: Verdict, context: Context, consumed: bool}> */
    private array $recorded = [];

    private bool $recording = false;

    public function startRecording(): void
    {
        $this->recording = true;
        $this->recorded = [];
        $this->engine()->observe(function (Verdict $verdict, Context $context, bool $consumed): void {
            $this->recorded[] = ['verdict' => $verdict, 'context' => $context, 'consumed' => $consumed];
        });
    }

    /** @return list<Verdict> */
    public function verdicts(?string $policy = null): array
    {
        $this->ensureRecording();

        return array_values(array_map(
            static fn (array $entry): Verdict => $entry['verdict'],
            array_filter($this->recorded, static fn (array $entry): bool => $policy === null || $entry['verdict']->policy === $policy),
        ));
    }

    public function assertAttempted(string $policy, ?int $times = null): void
    {
        $count = count(array_filter($this->recorded, static fn (array $entry): bool => $entry['consumed'] && $entry['verdict']->policy === $policy));

        $this->ensureRecording();

        if ($times === null) {
            Assert::assertGreaterThan(0, $count, "Policy [{$policy}] was never attempted.");
        } else {
            Assert::assertSame($times, $count, "Policy [{$policy}] was attempted {$count} times, not {$times}.");
        }
    }

    public function assertNotAttempted(string $policy): void
    {
        $this->assertAttempted($policy, 0);
    }

    public function assertLimited(string $policy, ?int $times = null): void
    {
        $count = count(array_filter($this->verdicts($policy), static fn (Verdict $verdict): bool => $verdict->denied()));

        if ($times === null) {
            Assert::assertGreaterThan(0, $count, "Policy [{$policy}] never refused anything.");
        } else {
            Assert::assertSame($times, $count, "Policy [{$policy}] refused {$count} times, not {$times}.");
        }
    }

    public function assertNotLimited(?string $policy = null): void
    {
        $refused = array_filter($this->verdicts($policy), static fn (Verdict $verdict): bool => $verdict->denied());

        Assert::assertSame([], array_values(array_map(static fn (Verdict $v): string => $v->policy, $refused)), 'Some attempts were refused.');
    }

    public function assertJailed(string $subject): void
    {
        $label = $this->subjects()->normalizeLabel($subject);

        Assert::assertTrue($this->jail()->isJailed($label), "[{$label}] is not jailed.");
    }

    public function assertNotJailed(string $subject): void
    {
        $label = $this->subjects()->normalizeLabel($subject);

        Assert::assertFalse($this->jail()->isJailed($label), "[{$label}] is jailed.");
    }

    private function ensureRecording(): void
    {
        if (! $this->recording) {
            Assert::fail('Call Limiting::fake() before asserting on limits.');
        }
    }
}
