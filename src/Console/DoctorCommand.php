<?php

declare(strict_types=1);

namespace Atannex\Limiting\Console;

use Atannex\Limiting\Context;
use Atannex\Limiting\Contracts\Store;
use Atannex\Limiting\Limiting;
use Atannex\Limiting\Stores\CacheStore;
use Atannex\Limiting\Stores\MemoryStore;
use Atannex\Limiting\Stores\RedisStore;
use Atannex\Limiting\Support\KeyGenerator;
use Illuminate\Console\Command;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Routing\Router;

/**
 * Checks the setup for the mistakes that quietly break rate limiting: a
 * store that isn't shared or atomic, no secret, untrusted proxies (everyone
 * gets the load balancer's IP), policies that don't parse.
 */
final class DoctorCommand extends Command
{
    protected $signature = 'limiting:doctor';

    protected $description = 'Check that rate limiting is set up safely';

    private int $problems = 0;

    public function handle(Limiting $limiting, Router $router, KeyGenerator $keys): int
    {
        $production = $this->laravel->environment('production');
        $config = $limiting->config();

        $this->section('Switches');
        ($config['enabled'] ?? true) ? $this->ok('Limiting is on.') : $this->problem('Limiting is off (LIMITING_ENABLED): nothing is limited.', $production);
        ($config['dry_run'] ?? false) ? $this->problem('Dry run is on (LIMITING_DRY_RUN): refusals are only reported.', $production) : $this->ok('Dry run is off.');

        $this->section('Secret');
        $secret = (string) ($config['secret'] ?? '') ?: (string) config('app.key');
        $secret !== '' ? $this->ok('Keys are HMAC-hashed with '.(($config['secret'] ?? null) ? 'LIMITING_SECRET' : 'APP_KEY').'.') : $this->problem('No LIMITING_SECRET or APP_KEY: keys use a public fallback secret, so anyone can compute them.');

        $this->section('Store');

        try {
            $store = $limiting->store();
        } catch (\Throwable $e) {
            $this->problem('The store cannot be created: '.$e->getMessage());

            return $this->finish();
        }

        $this->describeStore($store, $production);
        $this->checkRoundTrip($store, $keys);

        $this->section('Requests');
        $this->checkProxies();

        foreach (['limit' => 'limit', 'shield' => 'limit.shield'] as $key => $default) {
            $alias = (string) ($config['middleware'][$key] ?? $default);
            isset($router->getMiddleware()[$alias]) ? $this->ok("Middleware alias `{$alias}` is registered.") : $this->problem("Middleware alias `{$alias}` is missing.");
        }

        $this->section('Policies');

        foreach ($limiting->policies() as $name => $policy) {
            try {
                $limits = $policy->limits(Context::of('doctor'));

                foreach ($limits as $limit) {
                    $limiting->engine()->algorithm($limit->algorithmOr((string) ($config['default_algorithm'] ?? 'sliding-window')));
                }

                $this->ok("{$name}: ".($policy->isDynamic() ? 'decided per request' : implode(', ', array_map(fn ($l) => $l->name.' '.$l->describe(), $limits))));
            } catch (\Throwable $e) {
                $this->problem("{$name}: ".$e->getMessage());
            }
        }

        return $this->finish();
    }

    private function describeStore(Store $store, bool $production): void
    {
        if ($store instanceof MemoryStore) {
            $this->problem('The memory store keeps counts per process: every request starts at zero under php-fpm.', $production);

            return;
        }

        if ($store instanceof RedisStore) {
            $this->ok('Redis store: atomic compare-and-set, shared by every server on that Redis.');

            try {
                $skew = abs($store->time() - microtime(true));
                $skew < 1
                    ? $this->ok(sprintf('Clock agrees with Redis (%.0f ms apart).', $skew * 1000))
                    : $this->problem(sprintf('This server\'s clock is %.1f s off Redis; set LIMITING_CLOCK=store or fix NTP.', $skew));
            } catch (\Throwable) {
                // Reported by the round trip below.
            }

            return;
        }

        if ($store instanceof CacheStore) {
            $driver = get_class($store->repository()->getStore());
            $short = class_basename($driver);

            match (true) {
                $short === 'ArrayStore' => $this->problem('The cache store is `array`: counts live for one request only.', $production),
                $short === 'NullStore' => $this->problem('The cache store is `null`: nothing is ever counted.'),
                $short === 'FileStore' => $this->warnOnly('The cache store is `file`: exact, but only shared by processes on this one server.'),
                default => $this->ok("Cache store ({$short}) with atomic locks."),
            };

            if ($short === 'RedisStore') {
                $this->line('    Tip: LIMITING_STORE=redis skips the locks and is faster on the same Redis.');
            }

            return;
        }

        $this->ok('Custom store: '.get_class($store).'.');
    }

    private function checkRoundTrip(Store $store, KeyGenerator $keys): void
    {
        $key = $keys->probe();

        try {
            $started = microtime(true);

            for ($i = 0; $i < 20; $i++) {
                $store->update($key, static fn (?array $state): array => [['n' => ($state['n'] ?? 0) + 1], 30.0, null, true]);
            }

            $count = (int) ($store->get($key)['n'] ?? 0);
            $store->forget($key);
            $ms = (microtime(true) - $started) * 1000 / 20;

            $count === 20
                ? $this->ok(sprintf('Read-modify-write works (%.2f ms per update).', $ms))
                : $this->problem("Wrote 20 updates, read back {$count}: the store is losing writes.");
        } catch (\Throwable $e) {
            $this->problem('The store failed a round trip: '.$e->getMessage());
        }
    }

    private function checkProxies(): void
    {
        $proxies = null;

        if (class_exists(TrustProxies::class)) {
            try {
                $property = new \ReflectionProperty(TrustProxies::class, 'alwaysTrustProxies');
                $proxies = $property->getValue();
            } catch (\Throwable) {
                $proxies = null;
            }
        }

        if ($proxies === '*' || $proxies === ['*']) {
            $this->warnOnly('Every proxy is trusted (*): fine behind a load balancer that overwrites X-Forwarded-For, spoofable otherwise.');
        } elseif ($proxies) {
            $this->ok('Trusted proxies: '.implode(', ', (array) $proxies).'.');
        } else {
            $this->line('  <comment>?</comment> No trusted proxies are set. Behind a load balancer or CDN every visitor gets its IP; set them with $middleware->trustProxies(at: ...).');
        }
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->line("<options=bold>{$title}</>");
    }

    private function ok(string $message): void
    {
        $this->line("  <info>✓</info> {$message}");
    }

    private function warnOnly(string $message): void
    {
        $this->line("  <comment>!</comment> {$message}");
    }

    private function problem(string $message, bool $serious = true): void
    {
        if (! $serious) {
            $this->warnOnly($message);

            return;
        }

        $this->problems++;
        $this->line("  <error>✗</error> {$message}");
    }

    private function finish(): int
    {
        $this->newLine();

        if ($this->problems === 0) {
            $this->info('No problems found.');

            return self::SUCCESS;
        }

        $this->error("{$this->problems} problem(s) found.");

        return self::FAILURE;
    }
}
