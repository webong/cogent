<?php

declare(strict_types=1);

namespace Webong\Fluent;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Webong\Fluent\Support\CachedRelationAttributeStore;
use Webong\Fluent\Support\CachedRelationMacro;
use Webong\Fluent\Support\CachedRelationRegistry;
use Webong\Fluent\Support\EloquentBatchCounter;

class FluentServiceProvider extends ServiceProvider
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
