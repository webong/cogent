<?php

declare(strict_types=1);

namespace Webong\Cogent;

use Closure;
use Illuminate\Container\Container as ContainerInstance;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\ServiceProvider;
use Webong\Cogent\Cache\CacheKeyBuilder;
use Webong\Cogent\Cache\CogentCacheInvalidator;
use Webong\Cogent\Cache\CogentCacheNameBuilder;
use Webong\Cogent\Console\PlansCommand;
use Webong\Cogent\Cache\SurfaceCache;
use Webong\Cogent\Resources\OutputCache;
use Webong\Cogent\Resources\PreparedResourceCollection;
use Webong\Cogent\Resources\RenderGuard;
use Webong\Cogent\Resources\ResourcePreparer;
use Webong\Cogent\Support\CachedRelationAttributeStore;
use Webong\Cogent\Support\CachedRelationMacro;
use Webong\Cogent\Support\CachedRelationRegistry;
use Webong\Cogent\Support\EloquentBatchCounter;
use Webong\Cogent\Http\Middleware\RememberResponseMiddleware;

class CogentServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->scoped(
            EloquentBatchCounter::class,
            static fn (): EloquentBatchCounter => new EloquentBatchCounter,
        );

        $this->app->scoped(
            CachedRelationAttributeStore::class,
            static fn (): CachedRelationAttributeStore => new CachedRelationAttributeStore,
        );

        $this->app->scoped(
            CachedRelationRegistry::class,
            static fn (Container $app): CachedRelationRegistry => new CachedRelationRegistry(
                $app->make(CachedRelationAttributeStore::class),
            ),
        );

        $this->app->scoped(
            ResourcePreparer::class,
            static fn (Container $app): ResourcePreparer => new ResourcePreparer(
                $app->make(CachedRelationRegistry::class),
                $app->make(OutputCache::class),
            ),
        );

        $this->app->scoped(
            OutputCache::class,
            static fn (Container $app): OutputCache => new OutputCache(
                $app->make(CacheRepository::class),
                new SurfaceCache($app->make(CacheRepository::class), indexPrefix: 'cogent:output:index:', useStoreTags: false),
                new CacheKeyBuilder,
            ),
        );

        $this->mergeConfigFrom(
            __DIR__.'/../config/cogent.php',
            'cogent',
        );

        $this->app->scoped(
            CogentCacheNameBuilder::class,
            static fn (): CogentCacheNameBuilder => new CogentCacheNameBuilder,
        );

        $this->app->scoped(
            CogentCacheInvalidator::class,
            static fn (Container $app): CogentCacheInvalidator => new CogentCacheInvalidator(
                $app->make(CacheRepository::class),
                $app->make(CogentCacheNameBuilder::class),
            ),
        );
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        CachedRelationMacro::register();

        $this->app['router']->aliasMiddleware(
            'remember.response',
            RememberResponseMiddleware::class,
        );

        if ($this->app->runningInConsole()) {
            $this->commands([PlansCommand::class]);
        }

        $events = $this->app->make(Dispatcher::class);

        $events->listen(QueryExecuted::class, static function (QueryExecuted $query): void {
            if (RenderGuard::enabled()) {
                RenderGuard::queried($query);
            }
        });

        $events->listen(
            ['eloquent.saved: *', 'eloquent.deleted: *'],
            static function (string $event, array $payload): void {
                if (($payload[0] ?? null) instanceof Model) {
                    ContainerInstance::getInstance()->make(OutputCache::class)->forget($payload[0]);
                }
            },
        );

        Collection::macro('toResourceCollection', static function (string $resource): PreparedResourceCollection {
            return new PreparedResourceCollection($this, $resource);
        });

        if (! Request::hasMacro('remember')) {
            /**
             * Cache an entire controller response as input/output: the input is
             * the models plus anything the output varies on, and the output is
             * the rendered array. Auto-forgets when any rendered model changes.
             *
             * @param  array<string, mixed>  $vary
             * @param  Closure(): array<mixed>  $build
             * @return array<mixed>
             */
            Request::macro('remember', static function (string $resource, array $models, array $vary = [], int $ttl = 60, Closure $build): array {
                return app(OutputCache::class)->remember($resource, $models, $vary, $ttl, $build);
            });
        }

        if (! Response::hasMacro('remember')) {
            /**
             * Cache a response's content by resource, models, and vary params.
             * On a cache hit, returns a new Response with the cached content.
             * On a miss, stores the current content for future requests.
             *
             * @param  array<string, mixed>  $vary
             * @return static
             */
            Response::macro('remember', static function (string $resource, array $models, array $vary = [], int $ttl = 60): self {
                $cache = app(CacheRepository::class);
                $keys = app(CacheKeyBuilder::class);

                $identities = implode(',', array_map(
                    static fn (Model $model): string => $model::class.':'.$model->getKey(),
                    $models,
                ));

                $key = $keys->build('cogent', 'output', $resource, ['models' => $identities, 'vary' => $vary]);

                $cached = $cache->get($key);

                if ($cached !== null && is_string($cached)) {
                    return new static($cached, $this->getStatusCode(), $this->headers->all());
                }

                $cache->put($key, $this->getContent(), $ttl);

                return $this;
            });
        }
    }
}
