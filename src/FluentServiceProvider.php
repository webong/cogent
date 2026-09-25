<?php

declare(strict_types=1);

namespace Webong\Fluent;

use Illuminate\Support\ServiceProvider;
use Webong\Fluent\Support\CachedRelationAttributeStore;
use Webong\Fluent\Support\EloquentBatchCounter;

class FluentServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->scoped(
            CachedRelationAttributeStore::class,
            static fn (): CachedRelationAttributeStore => new CachedRelationAttributeStore,
        );

        $this->app->scoped(
            EloquentBatchCounter::class,
            static fn (): EloquentBatchCounter => new EloquentBatchCounter,
        );
    }
}
