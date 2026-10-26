<?php

declare(strict_types=1);

namespace Atannex\Limiting;

use Atannex\Limiting\Clock\StoreClock;
use Atannex\Limiting\Clock\SystemClock;
use Atannex\Limiting\Contracts\Clock;
use Atannex\Limiting\Contracts\Store;
use Atannex\Limiting\Events\Jailed;
use Atannex\Limiting\Events\Limited;
use Atannex\Limiting\Events\StoreFailed;
use Atannex\Limiting\Events\WouldLimit;
use Atannex\Limiting\Http\Headers;
use Atannex\Limiting\Http\Middleware\LimitRequests;
use Atannex\Limiting\Http\Middleware\Shield;
use Atannex\Limiting\Jail\Jail;
use Atannex\Limiting\Lists\AccessList;
use Atannex\Limiting\Stats\Recorder;
use Atannex\Limiting\Stores\StoreFactory;
use Atannex\Limiting\Subjects\SubjectResolver;
use Atannex\Limiting\Support\KeyGenerator;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

final class LimitingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/limiting.php', 'limiting');

        $this->app->singleton(Clock::class, function (Application $app) {
            $clock = new SystemClock;

            $name = (string) $app['config']->get('limiting.store', 'cache');

            if ($app['config']->get('limiting.clock') === 'store' && $app['config']->get("limiting.stores.{$name}.driver", $name) === 'redis') {
                $store = $app->make(Store::class);

                return $store instanceof Stores\RedisStore ? new StoreClock($store, $clock) : $clock;
            }

            return $clock;
        });

        $this->app->singleton(StoreFactory::class, fn (Application $app) => new StoreFactory($app));
        $this->app->singleton(Store::class, fn (Application $app) => $app->make(StoreFactory::class)->make($app['config']->get('limiting.store', 'cache')));

        $this->app->singleton(KeyGenerator::class, function (Application $app) {
            $config = $app['config'];
            $secret = (string) ($config->get('limiting.secret') ?: $config->get('app.key'));

            if (str_starts_with($secret, 'base64:')) {
                $secret = (string) base64_decode(substr($secret, 7), true);
            }

            return new KeyGenerator($secret !== '' ? $secret : 'atannex-limiting', (string) $config->get('limiting.prefix', 'limiting:'));
        });

        $this->app->singleton(SubjectResolver::class, fn (Application $app) => new SubjectResolver(
            (array) $app['config']->get('limiting.subjects', []),
            (string) $app['config']->get('limiting.default_by', 'actor'),
            (string) $app['config']->get('limiting.jail.by', 'ip'),
        ));

        $this->app->singleton(AccessList::class, fn (Application $app) => new AccessList(
            $app->make(SubjectResolver::class),
            array_values((array) $app['config']->get('limiting.allow', [])),
            array_values((array) $app['config']->get('limiting.deny', [])),
        ));

        $this->app->singleton(Jail::class, fn (Application $app) => new Jail(
            $app->make(Store::class),
            $app->make(KeyGenerator::class),
            $app->make(Clock::class),
            $app->make(Dispatcher::class),
            (array) $app['config']->get('limiting.jail', []),
        ));

        $this->app->singleton(Recorder::class, fn (Application $app) => new Recorder(
            $app->make(Store::class),
            $app->make(KeyGenerator::class),
            $app->make(Clock::class),
            (array) $app['config']->get('limiting.stats', []),
        ));

        $this->app->singleton(Engine::class, fn (Application $app) => new Engine(
            $app->make(Store::class),
            $app->make(Clock::class),
            $app->make(KeyGenerator::class),
            $app->make(SubjectResolver::class),
            $app->make(Jail::class),
            $app->make(AccessList::class),
            $app->make(Recorder::class),
            $app->make(Dispatcher::class),
            (array) $app['config']->get('limiting', []),
        ));

        $this->app->singleton(Limiting::class, fn (Application $app) => new Limiting($app, (array) $app['config']->get('limiting', [])));

        $this->app->bind(Headers::class, fn (Application $app) => new Headers(
            (string) $app['config']->get('limiting.headers.style', 'both'),
            (int) $app['config']->get('limiting.headers.jitter', 0),
            fn () => $app->make(Clock::class)->now(),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/limiting.php' => config_path('limiting.php')], 'limiting-config');

            $this->commands([
                Console\PoliciesCommand::class,
                Console\InspectCommand::class,
                Console\ResetCommand::class,
                Console\JailCommand::class,
                Console\ReleaseCommand::class,
                Console\JailedCommand::class,
                Console\StatsCommand::class,
                Console\DoctorCommand::class,
            ]);
        }

        $this->callAfterResolving(Router::class, function (Router $router): void {
            $router->aliasMiddleware((string) config('limiting.middleware.limit', 'limit'), LimitRequests::class);
            $router->aliasMiddleware((string) config('limiting.middleware.shield', 'limit.shield'), Shield::class);
        });

        $this->registerLogging();
    }

    private function registerLogging(): void
    {
        $events = $this->app->make(Dispatcher::class);
        $log = fn () => $this->app->make('log')->channel(config('limiting.log.channel'));

        $events->listen(Jailed::class, function (Jailed $event) use ($log): void {
            if (config('limiting.log.jailed', true)) {
                $log()->warning('Rate limiting: jailed '.$event->subject, [
                    'seconds' => (int) round($event->seconds),
                    'reason' => $event->reason,
                    'policy' => $event->policy,
                    'sentence' => $event->sentence,
                ]);
            }
        });

        $events->listen(Limited::class, function (Limited $event) use ($log): void {
            if (config('limiting.log.limited', false)) {
                $log()->notice("Rate limiting: {$event->decision->policy}.{$event->decision->limit} refused {$event->decision->subject}", $event->decision->toArray());
            }
        });

        $events->listen(WouldLimit::class, function (WouldLimit $event) use ($log): void {
            if (config('limiting.log.shadow', true)) {
                $log()->info("Rate limiting (shadow): {$event->decision->policy}.{$event->decision->limit} would refuse {$event->decision->subject}", $event->decision->toArray());
            }
        });

        $events->listen(StoreFailed::class, function (StoreFailed $event) use ($log): void {
            if (config('limiting.log.store_failures', true)) {
                $log()->error("Rate limiting store failed on {$event->policy}.{$event->limit}; failed ".($event->failedClosed ? 'closed' : 'open'), [
                    'exception' => $event->exception,
                ]);
            }
        });
    }
}
