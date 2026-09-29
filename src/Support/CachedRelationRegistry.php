<?php

declare(strict_types=1);

namespace Webong\Fluent\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use ReflectionMethod;
use Throwable;
use Webong\Fluent\Attributes\CachedRelation;

/**
 * Turns a relation method on a model into a cached relation definition, and
 * remembers every definition so a save can forget the keys it made stale.
 */
class CachedRelationRegistry
{
    public function __construct(
        private readonly CachedRelationAttributeStore $store,
    ) {
    }

    /**
     * @var array<string, CachedRelationDefinition>
     */
    private array $definitions = [];

    /**
     * @var array<class-string<Model>, array<string, true>>
     */
    private array $byRelatedClass = [];

    /**
     * @var array<class-string<Model>, true>
     */
    private array $watched = [];

    /**
     * @param  class-string<Model>  $modelClass
     */
    public function for(string $modelClass, string $relation): CachedRelationDefinition
    {
        return $this->definitions[$this->key($modelClass, $relation)] ??= $this->build($modelClass, $relation);
    }

    /**
     * Take part in invalidation with a definition that was built elsewhere, such
     * as one declared on a collection.
     */
    public function remember(string $key, CachedRelationDefinition $definition): void
    {
        $this->definitions[$key] = $definition;
        $this->byRelatedClass[$definition->relatedClass][$key] = true;

        $this->watch($definition->relatedClass);
    }

    /**
     * Forget every key the given related model took part in, both in the cache
     * and in the request scoped store.
     */
    public function forget(Model $related): void
    {
        CachedRelationCollector::flush();

        foreach ($this->byRelatedClass[$related::class] ?? [] as $key => $ignored) {
            foreach ($this->definitions[$key]->localValuesFor($related) as $localValue) {
                $cacheKey = $this->definitions[$key]->cacheKeyFor($localValue);

                Cache::forget($cacheKey);

                $this->store->forget($cacheKey);
            }
        }
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function build(string $modelClass, string $relation): CachedRelationDefinition
    {
        if (! method_exists($modelClass, $relation)) {
            throw $this->notConfigured($modelClass, $relation);
        }

        $method = new ReflectionMethod($modelClass, $relation);

        if (! $method->isPublic()) {
            throw $this->notConfigured($modelClass, $relation);
        }

        $resolved = ($method->getAttributes(CachedRelation::class)[0] ?? null)?->newInstance();
        $attribute = $resolved instanceof CachedRelation ? $resolved : null;

        $prototype = new $modelClass;
        $depth = CachedRelationCollector::depth();

        try {
            $declared = $prototype->{$relation}();
        } catch (Throwable $exception) {
            CachedRelationCollector::drain($depth);

            throw $exception;
        }

        $declaredOverrides = CachedRelationCollector::drain($depth);

        if ($attribute === null && $declaredOverrides === null) {
            throw $this->notConfigured($modelClass, $relation);
        }

        if (! $declared instanceof Relation) {
            throw $this->notConfigured($modelClass, $relation);
        }

        $definition = $this->fromRelation($modelClass, $relation, $declared, $this->merge($attribute, $declaredOverrides));

        $this->remember($this->key($modelClass, $relation), $definition);

        return $definition;
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function key(string $modelClass, string $relation): string
    {
        return $modelClass.'|'.$relation;
    }

    /**
     * The attribute holds the defaults, the helper inside the relation wins.
     */
    private function merge(?CachedRelation $attribute, ?CachedRelationOverrides $overrides): CachedRelationOverrides
    {
        if ($attribute === null) {
            return $overrides ?? CachedRelationOverrides::none();
        }

        $fromAttribute = new CachedRelationOverrides(
            key: $attribute->key,
            ttl: $attribute->ttl,
            resolver: $attribute->resolver,
            collection: $attribute->collection,
        );

        return $overrides === null ? $fromAttribute : $overrides->merge($fromAttribute);
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function fromRelation(
        string $modelClass,
        string $relation,
        Relation $declared,
        CachedRelationOverrides $overrides,
    ): CachedRelationDefinition {
        [$many, $localKey, $relatedKey, $relatedForeignKey, $relatedMorphType, $morphClass] = match (true) {
            $declared instanceof MorphOne => [false, $declared->getLocalKeyName(), null, $declared->getForeignKeyName(), $declared->getMorphType(), $declared->getMorphClass()],
            $declared instanceof MorphMany => [true, $declared->getLocalKeyName(), null, $declared->getForeignKeyName(), $declared->getMorphType(), $declared->getMorphClass()],
            $declared instanceof HasOne => [false, $declared->getLocalKeyName(), null, $declared->getForeignKeyName(), null, null],
            $declared instanceof HasMany => [true, $declared->getLocalKeyName(), null, $declared->getForeignKeyName(), null, null],
            $declared instanceof BelongsTo => [false, $declared->getForeignKeyName(), $declared->getOwnerKeyName(), null, null, null],
            $declared instanceof BelongsToMany => [true, $declared->getParentKeyName(), null, null, null, null],
            default => throw $this->notConfigured($modelClass, $relation),
        };

        /** @var Model $related */
        $related = $declared->getRelated();

        return new CachedRelationDefinition(
            modelClass: $modelClass,
            relation: $relation,
            localKey: $overrides->localKey ?? $localKey,
            relatedClass: $related::class,
            many: $many,
            collectionClass: $many ? $overrides->collection : null,
            relatedKey: $relatedKey,
            relatedForeignKey: $overrides->foreignKey ?? $relatedForeignKey,
            relatedMorphType: $relatedMorphType,
            morphClass: $morphClass,
            key: $overrides->key,
            ttl: $overrides->ttl ?? 86400,
            resolver: $overrides->resolver,
        );
    }

    /**
     * Listen for the changes that make a cached relation stale, on the model the
     * relation points at rather than on the model that declares it.
     *
     * @param  class-string<Model>  $relatedClass
     */
    private function watch(string $relatedClass): void
    {
        if (isset($this->watched[$relatedClass])) {
            return;
        }

        $this->watched[$relatedClass] = true;

        $relatedClass::saved(function (Model $related): void {
            $this->forget($related);
        });

        $relatedClass::deleted(function (Model $related): void {
            $this->forget($related);
        });

        Model::getEventDispatcher()?->listen(
            "eloquent.restored: {$relatedClass}",
            function (Model $related): void {
                $this->forget($related);
            },
        );
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function notConfigured(string $modelClass, string $relation): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf(
            'No cached relation is configured for [%s]. Add #[CachedRelation] to [%s::%s()] or wrap it with cached().',
            $relation,
            $modelClass,
            $relation,
        ));
    }
}
