<?php

declare(strict_types=1);

namespace Atannex\Limiting;

use Illuminate\Http\Request;

/**
 * Who and what is being limited: an HTTP request, or (outside HTTP) a
 * subject you name, like 'tenant:5' or ['tenant' => 5, 'provider' => 'mtn'].
 */
final class Context
{
    /** @param  array<string, mixed>  $data */
    public function __construct(
        public readonly ?Request $request = null,
        public readonly ?string $subject = null,
        public readonly array $data = [],
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self($request);
    }

    /**
     * @param  Request|string|int|array<string, scalar>|null  $subject
     */
    public static function of(Request|string|int|array|null $subject = null): self
    {
        if ($subject instanceof Request) {
            return new self($subject);
        }

        if (is_array($subject)) {
            $parts = [];

            foreach ($subject as $key => $value) {
                $parts[] = is_int($key) ? (string) $value : "{$key}:{$value}";
            }

            return new self(null, $parts === [] ? null : implode('|', $parts), $subject);
        }

        return new self(null, $subject === null || $subject === '' ? null : (string) $subject);
    }

    public function withSubject(string $subject): self
    {
        return new self($this->request, $subject, $this->data);
    }

    public function hasRequest(): bool
    {
        return $this->request !== null;
    }
}
