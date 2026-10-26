<?php

declare(strict_types=1);

namespace Atannex\Limiting\Subjects;

use Atannex\Limiting\Context;
use Atannex\Limiting\Limit;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

/**
 * Turns a limit's `by` keys into a subject label such as
 * "email:bob@example.com|ip:203.0.113.9" or "user:42".
 *
 * Labels are readable (the jail list and stats show them) but never reach the
 * store as written: the KeyGenerator hashes them with a secret first. Secret
 * values (session ids, bearer tokens, header values) are hashed already here.
 */
final class SubjectResolver
{
    private const SIMPLE = ['ip', 'subnet', 'user', 'actor', 'session', 'token', 'fingerprint', 'global', 'route', 'method', 'path'];

    /** @param  array<string, mixed>  $config  the limiting.subjects config */
    public function __construct(
        private readonly array $config = [],
        private readonly string $defaultBy = 'actor',
        private readonly string $jailBy = 'ip',
    ) {}

    public static function isKnown(string $key): bool
    {
        return in_array($key, self::SIMPLE, true)
            || (bool) preg_match('/^(header|input|email|route):[^|]+$/', $key);
    }

    public function label(Limit $limit, Context $context): string
    {
        if ($context->subject !== null) {
            return $limit->perRoute && $context->request !== null
                ? $context->subject.'|'.$this->resolve('route', $context->request)
                : $context->subject;
        }

        if ($context->request === null) {
            return 'global';
        }

        $keys = $limit->by !== [] ? $limit->by : [$this->defaultBy];

        if ($limit->perRoute) {
            $keys[] = 'route';
        }

        $parts = [];

        foreach ($keys as $key) {
            $parts[] = $this->resolve($key, $context->request) ?? $this->resolve('actor', $context->request);
        }

        return implode('|', array_values(array_unique($parts)));
    }

    /** The label a limit's jail locks up: by default the sender's IP. */
    public function jailLabel(Limit $limit, Context $context): string
    {
        if ($context->request === null) {
            return $context->subject ?? 'global';
        }

        $key = $limit->jail['by'] ?? $this->jailBy;

        return $this->resolve($key, $context->request) ?? $this->resolve('ip', $context->request);
    }

    /**
     * Labels that identify the sender, for jail and list checks.
     *
     * @return list<string>
     */
    public function identities(Context $context): array
    {
        $labels = [];

        if ($context->subject !== null) {
            $labels[] = $context->subject;
        }

        if ($context->request !== null) {
            $labels[] = $this->resolve('ip', $context->request);

            if ($user = $this->resolve('user', $context->request)) {
                $labels[] = $user;
            }

            if ($this->jailBy !== 'ip' && $this->jailBy !== 'user' && ($custom = $this->resolve($this->jailBy, $context->request))) {
                $labels[] = $custom;
            }
        }

        return array_values(array_unique($labels));
    }

    /** Normalize a label typed by a person, e.g. "203.0.113.9" becomes "ip:203.0.113.9". */
    public function normalizeLabel(string $label): string
    {
        $label = trim($label);

        if (IpAddress::isIp($label)) {
            return 'ip:'.$this->ip($label);
        }

        if (str_starts_with($label, 'ip:') && IpAddress::isIp(substr($label, 3))) {
            return 'ip:'.$this->ip(substr($label, 3));
        }

        if (str_starts_with($label, 'email:')) {
            return 'email:'.Normalizer::email(substr($label, 6));
        }

        return $label;
    }

    public function ip(string $ip): string
    {
        return IpAddress::normalize($ip, (int) ($this->config['ipv4_prefix'] ?? 32), (int) ($this->config['ipv6_prefix'] ?? 64)) ?? 'unknown';
    }

    public function resolve(string|Closure $key, Request $request): ?string
    {
        if ($key instanceof Closure) {
            $value = $key($request);

            return $value === null || $value === '' ? null : 'custom:'.(is_scalar($value) ? (string) $value : $this->digest(json_encode($value)));
        }

        [$type, $argument] = str_contains($key, ':') ? explode(':', $key, 2) : [$key, null];

        return match ($type) {
            'ip' => 'ip:'.$this->ip((string) $request->ip()),
            'subnet' => 'subnet:'.(IpAddress::normalize((string) $request->ip(), (int) ($this->config['subnet_v4'] ?? 24), (int) ($this->config['subnet_v6'] ?? 48)) ?? 'unknown'),
            'user' => ($id = $this->userId($request)) !== null ? 'user:'.$id : null,
            'actor' => $this->resolve('user', $request) ?? $this->resolve('ip', $request),
            'session' => $request->hasSession() && $request->session()->getId() ? 'session:'.$this->digest($request->session()->getId()) : null,
            'token' => ($token = $request->bearerToken()) ? 'token:'.$this->digest($token) : null,
            'fingerprint' => 'fingerprint:'.$this->digest(implode("\n", [
                $this->ip((string) $request->ip()),
                (string) $request->userAgent(),
                (string) $request->header('Accept-Language'),
                (string) $request->header('Accept-Encoding'),
            ])),
            'global' => 'global',
            'route' => $argument === null ? 'route:'.$this->routeIdentity($request) : $this->routeParameter($request, $argument),
            'method' => 'method:'.$request->getMethod(),
            'path' => 'path:/'.ltrim(Normalizer::text(rawurldecode($request->path())), '/'),
            'header' => ($value = $request->header($argument)) !== null && $value !== '' ? 'header:'.strtolower($argument).':'.$this->digest((string) $value) : null,
            'input' => $this->input($request, $argument, false),
            'email' => $this->input($request, $argument, true),
            default => null,
        };
    }

    private function input(Request $request, string $field, bool $email): ?string
    {
        $value = $request->input($field);

        if (is_array($value)) {
            $value = json_encode($value);
        }

        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        $value = $email && ($this->config['normalize_email'] ?? true)
            ? Normalizer::email((string) $value)
            : Normalizer::text((string) $value);

        if (mb_strlen($value) > 254) {
            $value = $this->digest($value);
        }

        return ($email ? 'email:' : "input:{$field}:").$value;
    }

    private function userId(Request $request): string|int|null
    {
        $user = $request->user();

        return $user instanceof Authenticatable ? $user->getAuthIdentifier() : null;
    }

    private function routeIdentity(Request $request): string
    {
        $route = $request->route();

        if ($route instanceof Route) {
            return $route->getName() ?? implode(',', $route->methods()).' '.$route->uri();
        }

        return $request->getMethod().' /'.ltrim($request->path(), '/');
    }

    private function routeParameter(Request $request, string $name): ?string
    {
        $route = $request->route();
        $value = $route instanceof Route ? $route->parameter($name) : null;

        if ($value instanceof Model) {
            $value = $value->getRouteKey();
        }

        return $value === null || $value === '' || ! is_scalar($value) ? null : "route:{$name}:{$value}";
    }

    private function digest(string $value): string
    {
        return substr(hash('sha256', $value), 0, 20);
    }
}
