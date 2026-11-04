<?php

declare(strict_types=1);

namespace Webong\Cogent;

use Illuminate\Container\Container as ContainerInstance;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\ServiceProvider;
use Webong\Cogent\Cache\CacheKeyBuilder;
use Webong\Cogent\Console\PlansCommand;
use Webong\Cogent\Cache\SurfaceCache;
use Webong\Cogent\Resources\OutputCache;
use Webong\Cogent\Resources\RenderGuard;
use Webong\Cogent\Resources\ResourcePreparer;
use Webong\Cogent\Support\CachedRelationAttributeStore;
use Webong\Cogent\Support\CachedRelationMacro;
use Webong\Cogent\Support\CachedRelationRegistry;
use Webong\Cogent\Support\EloquentBatchCounter;

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
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        CachedRelationMacro::register();

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
    }
}
