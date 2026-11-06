<?php

declare(strict_types=1);

namespace Webong\Cogent\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionNamedType;
use Webong\Cogent\Attributes\CachedRelation;
use Webong\Cogent\Support\CachedRelationDefinition;
use Webong\Cogent\Support\CachedRelationRegistry;

/**
 * Marks a model as declaring cacheable relations.
 *
 * #[CachedRelation] and cached() work on any model, this trait only adds the
 * lookup and the invalidation hooks on top of them.
 */
trait DefinesCachedRelations
{
    /**
     * @throws InvalidArgumentException When the relation declares no cached configuration.
     */
    public function cachedDefinition(string $relation): CachedRelationDefinition
    {
        return $this->cachedRelationRegistry()->for($this::class, $relation);
    }

    public function cachedDefinitionFor(string $relation): ?CachedRelationDefinition
    {
        try {
            return $this->cachedDefinition($relation);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    protected function cachedRelationRegistry(): CachedRelationRegistry
    {
        return app(CachedRelationRegistry::class);
    }

    /**
     * Register invalidation listeners for every cached relation on this model.
     *
     * Runs when the model class is first booted, so workers that save or delete
     * models without ever reading a cached relation still register listeners and
     * can forget stale cache entries.
     *
     * Registers a listener on the `eloquent.booted:{class}` event so that
     * relation definitions are built after boot completes, avoiding
     * `LogicException` from `bootIfNotBooted()` when `new $modelClass` is
     * called during `boot()`.
     */
    protected static function bootDefinesCachedRelations(): void
    {
        $modelClass = static::class;

        $registry = app(CachedRelationRegistry::class);
        $registry->registerModelClass($modelClass);

        $dispatcher = Model::getEventDispatcher();
        if ($dispatcher === null) {
            return;
        }

        $dispatcher->listen("eloquent.booted: {$modelClass}", function () use ($modelClass, $registry): void {
            foreach ((new ReflectionClass($modelClass))->getMethods() as $method) {
                if (! $method->isPublic()) {
                    continue;
                }

                $returnType = $method->getReturnType();
                if (! $returnType instanceof ReflectionNamedType || ! is_subclass_of($returnType->getName(), Relation::class)) {
                    continue;
                }

                try {
                    $registry->for($modelClass, $method->name);
                } catch (InvalidArgumentException) {
                    // Not a cached relation, skip.
                }
            }
        });
    }
}
