<?php

declare(strict_types=1);

namespace Atannex\Limiting;

use Atannex\Limiting\Exceptions\InvalidLimit;
use Closure;
use Illuminate\Http\Request;

/**
 * A named set of limits; a request must pass them all.
 *
 * Defined as limits (fixed) or as a closure that picks limits per request:
 *
 *     Limiting::define('api', fn (?Request $request) => $request?->user()?->isPartner()
 *         ? Limit::perMinute(1000)->by('user')
 *         : [Limit::perSecond(5)->by('actor'), Limit::perDay(10_000)->by('actor')]);
 */
final class Policy
{
    /** @var list<Limit>|null */
    private ?array $fixed = null;

    /** @param  Limit|array<int|string, Limit|array<string, mixed>>|Closure(Request|null, Context): (Limit|array<Limit>|null)  $definition */
    public function __construct(public readonly string $name, private readonly Limit|array|Closure $definition)
    {
        if (! $definition instanceof Closure) {
            $this->fixed = $this->normalize($definition);
        }
    }

    /** @return list<Limit> the limits that apply in this context */
    public function limits(Context $context): array
    {
        $limits = $this->fixed ?? $this->normalize(($this->definition)($context->request, $context));

        return array_values(array_filter($limits, static fn (Limit $limit): bool => ! $limit->unlimited && $limit->appliesTo($context)));
    }

    public function isDynamic(): bool
    {
        return $this->fixed === null;
    }

    /** @return list<Limit>|null the limits, when they don't depend on the request */
    public function fixedLimits(): ?array
    {
        return $this->fixed;
    }

    /** @return list<Limit> */
    private function normalize(mixed $definition): array
    {
        if ($definition === null) {
            return [];
        }

        if ($definition instanceof Limit) {
            $definition = [$definition];
        }

        if (! is_array($definition)) {
            throw new InvalidLimit("Policy \"{$this->name}\" must give a Limit, a list of limits or null.");
        }

        // One limit written as a config array.
        if (isset($definition['allow']) || isset($definition['concurrent']) || isset($definition['quota'])) {
            $definition = [$definition];
        }

        $limits = [];
        $names = [];

        foreach (array_values($definition) as $index => $limit) {
            $limit = is_array($limit) ? Limit::fromArray($limit) : $limit;

            if (! $limit instanceof Limit) {
                throw new InvalidLimit("Policy \"{$this->name}\" has an entry that is not a limit.");
            }

            $name = $limit->name ?? $this->autoName($limit, $index);

            if (isset($names[$name])) {
                $name .= '-'.$index;
            }

            $names[$name] = true;
            $limit->name ??= $name;
            $limits[] = $limit;
        }

        return $limits;
    }

    private function autoName(Limit $limit, int $index): string
    {
        if ($limit->unlimited) {
            return 'unlimited';
        }

        return match ($limit->algorithm) {
            Limit::CONCURRENCY => 'concurrent',
            Limit::QUOTA => $limit->period ?? 'quota',
            default => str_replace('/', '-per-', $limit->describe()),
        };
    }
}
