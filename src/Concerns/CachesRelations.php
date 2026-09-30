<?php

declare(strict_types=1);

namespace Webong\Cogent\Concerns;

use Closure;
use DateInterval;
use DateTimeInterface;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use LogicException;
use Webong\Cogent\Support\CachedRelationDefinition;
use Webong\Cogent\Support\CachedRelationRegistry;

/**
 * Hydrate relations from the cache, using the declaration the model makes for
 * each relation with #[CachedRelation] or cached().
 *
 * A collection may still implement the deprecated cachedRelationConfig() to
 * keep caches that predate model defined ones.
 *
 * @mixin PlugsRelations
 */
trait CachesRelations
{
    /**
     * Attach one or more cached relations to every model in the collection.
     *
     * @param  string|list<string>  $relations
     */
    public function withCached(string|array $relations): static
    {
        foreach ((array) $relations as $relation) {
            $this->loadCached($relation);
        }

        return $this;
    }

    public function loadCached(string $relation): static
    {
        if ($this->isEmpty()) {
            return $this;
        }

        $definition = $this->cachedRelationDefinition($relation);

        if ($definition->many) {
            return $this->plugCachedCollection(
                relation: $relation,
                localKey: $definition->localKey,
                relatedClass: $definition->relatedClass,
                resolver: $definition->resolve(...),
                cacheKey: $definition->cacheKeyFor(...),
                ttl: $definition->ttl,
                collectionClass: $definition->collectionClass ?? Collection::class,
                missingOnly: false,
            );
        }

        return $this->plugCached(
            relation: $relation,
            localKey: $definition->localKey,
            relatedClass: $definition->relatedClass,
            resolver: $definition->resolve(...),
            cacheKey: $definition->cacheKeyFor(...),
            ttl: $definition->ttl,
        );
    }

    public function loadMissingCached(string $relation): static
    {
        if ($this->isEmpty()) {
            return $this;
        }

        $definition = $this->cachedRelationDefinition($relation);

        if ($definition->many) {
            return $this->plugCachedCollection(
                relation: $relation,
                localKey: $definition->localKey,
                relatedClass: $definition->relatedClass,
                resolver: $definition->resolve(...),
                cacheKey: $definition->cacheKeyFor(...),
                ttl: $definition->ttl,
                collectionClass: $definition->collectionClass ?? Collection::class,
                missingOnly: true,
            );
        }

        return $this->plugCachedMissing(
            relation: $relation,
            localKey: $definition->localKey,
            relatedClass: $definition->relatedClass,
            resolver: $definition->resolve(...),
            cacheKey: $definition->cacheKeyFor(...),
            ttl: $definition->ttl,
        );
    }

    public function relationCached(string $relation, mixed $localValue = null): bool
    {
        if ($this->isEmpty()) {
            return false;
        }

        $definition = $this->cachedRelationDefinition($relation);

        if (func_num_args() >= 2) {
            return Cache::has($definition->cacheKeyFor($localValue));
        }

        /** @var list<mixed> $localValues */
        $localValues = $this
            ->pluck($definition->localKey)
            ->filter(static fn (mixed $value): bool => $value !== null)
            ->unique()
            ->values()
            ->all();

        return $localValues !== []
            && collect($localValues)->every(fn (mixed $value): bool => Cache::has($definition->cacheKeyFor($value)));
    }

    public function getCachedRelation(string $relation, mixed $localValue): ?Model
    {
        $definition = $this->cachedRelationDefinition($relation);
        $attributes = Cache::get($definition->cacheKeyFor($localValue));

        if (! is_array($attributes) || $definition->many) {
            return null;
        }

        /** @var class-string<Model> $relatedClass */
        $relatedClass = $definition->relatedClass;

        return (new $relatedClass)->newFromBuilder($attributes);
    }

    /**
     * Resolve how a cached relation is hydrated, preferring the declaration on
     * the model and falling back to the legacy collection configuration.
     *
     * @throws InvalidArgumentException When the relation declares no cached configuration.
     */
    protected function cachedRelationDefinition(string $relation): CachedRelationDefinition
    {
        $modelClass = $this->cachedModelClass();

        if ($modelClass !== null) {
            try {
                return $this->cachedRelationRegistry()->for($modelClass, $relation);
            } catch (InvalidArgumentException) {
                // The model did not declare it, a collection may still configure it.
            }
        }

        try {
            return $this->legacyCachedRelationDefinition($relation);
        } catch (InvalidArgumentException) {
            if ($modelClass !== null) {
                throw $this->notConfigured($modelClass, $relation);
            }

            throw new LogicException(sprintf(
                'A cached relation for [%s] is declared on the model, but an empty collection has no model to read it from.',
                $relation,
            ));
        }
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

    /**
     * @return class-string<Model>|null
     */
    private function cachedModelClass(): ?string
    {
        $model = $this->first();

        return $model === null ? null : $model::class;
    }

    private function cachedRelationRegistry(): CachedRelationRegistry
    {
        return Container::getInstance()->make(CachedRelationRegistry::class);
    }

    /**
     * @throws InvalidArgumentException
     */
    private function legacyCachedRelationDefinition(string $relation): CachedRelationDefinition
    {
        $settings = $this->cachedRelationConfig($relation);

        $many = ($settings['collection'] ?? false) === true;
        $collectionClass = $settings['collectionClass'] ?? Collection::class;

        if (! is_string($settings['localKey'] ?? null)
            || ! is_string($settings['relatedClass'] ?? null)
            || ! is_string($collectionClass)
            || ! ($settings['resolver'] ?? null) instanceof Closure
            || ! ($settings['cacheKey'] ?? null) instanceof Closure) {
            throw new InvalidArgumentException(sprintf(
                'The cached relation [%s] must configure a [localKey], a [relatedClass], a [resolver] and a [cacheKey].',
                $relation,
            ));
        }

        $ttl = $settings['ttl'] ?? 86400;

        if (! is_int($ttl) && ! $ttl instanceof DateTimeInterface && ! $ttl instanceof DateInterval) {
            throw new InvalidArgumentException(sprintf(
                'The cached relation [%s] must configure its [ttl] as seconds, a DateInterval or a DateTimeInterface.',
                $relation,
            ));
        }

        $definition = new CachedRelationDefinition(
            modelClass: $this->cachedModelClass(),
            relation: $relation,
            localKey: $settings['localKey'],
            relatedClass: $settings['relatedClass'],
            many: $many,
            collectionClass: $many ? $collectionClass : null,
            key: $settings['cacheKey'],
            ttl: $ttl,
            resolver: static fn (Builder $query, array $localValues): Collection => ($settings['resolver'])($localValues),
        );

        $this->cachedRelationRegistry()->remember($this::class.'|'.$relation, $definition);

        return $definition;
    }

    /**
     * Configure a cached relation on the collection itself.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     *
     * @deprecated Declare the cache on the model with #[CachedRelation] or cached() instead.
     *             A collection may still implement this to keep older caches working.
     */
    protected function cachedRelationConfig(string $relation): array
    {
        throw new InvalidArgumentException(sprintf('No cached relation is configured for [%s].', $relation));
    }
}
