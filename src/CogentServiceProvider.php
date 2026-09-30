<?php

declare(strict_types=1);

namespace Webong\Cogent;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
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


    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        CachedRelationMacro::register();
    }
}
