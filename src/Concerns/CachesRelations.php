<?php

declare(strict_types=1);

namespace Webong\Fluent\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
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
        $config = $this->cachedRelationConfig($relation);

        if (($config['collection'] ?? false) === true) {
            return $this->plugCachedCollection(
                relation: $relation,
                localKey: $config['localKey'],
                relatedClass: $config['relatedClass'],
                resolver: $config['resolver'],
                cacheKey: $config['cacheKey'],
                ttl: $config['ttl'],
                collectionClass: $config['collectionClass'] ?? Collection::class,
                missingOnly: false,
            );
        }

        return $this->plugCached(
            relation: $relation,
            localKey: $config['localKey'],
            relatedClass: $config['relatedClass'],
            resolver: $config['resolver'],
            cacheKey: $config['cacheKey'],
            ttl: $config['ttl'],
        );
    }

    public function loadMissingCached(string $relation): static
    {
        $config = $this->cachedRelationConfig($relation);

        if (($config['collection'] ?? false) === true) {
            return $this->plugCachedCollection(
                relation: $relation,
                localKey: $config['localKey'],
                relatedClass: $config['relatedClass'],
                resolver: $config['resolver'],
                cacheKey: $config['cacheKey'],
                ttl: $config['ttl'],
                collectionClass: $config['collectionClass'] ?? Collection::class,
                missingOnly: true,
            );
        }

        return $this->plugCachedMissing(
            relation: $relation,
            localKey: $config['localKey'],
            relatedClass: $config['relatedClass'],
            resolver: $config['resolver'],
            cacheKey: $config['cacheKey'],
            ttl: $config['ttl'],
        );
    }

    public function relationCached(string $relation, mixed $localValue = null): bool
    {
        $config = $this->cachedRelationConfig($relation);
        $cacheKey = $config['cacheKey'];

        if (func_num_args() >= 2) {
            return Cache::has($cacheKey($localValue));
        }

        if ($this->isEmpty()) {
            return false;
        }

        /** @var list<mixed> $localValues */
        $localValues = $this
            ->pluck($config['localKey'])
            ->filter(static fn (mixed $value): bool => $value !== null)
            ->unique()
            ->values()
            ->all();

        return $localValues !== []
            && collect($localValues)->every(fn (mixed $value): bool => Cache::has($cacheKey($value)));
    }

    public function getCachedRelation(string $relation, mixed $localValue): ?Model
    {
        $config = $this->cachedRelationConfig($relation);
        $attributes = Cache::get($config['cacheKey']($localValue));

        if (! is_array($attributes)) {
            return null;
        }

        /** @var class-string<Model> $relatedClass */
        $relatedClass = $config['relatedClass'];

        return (new $relatedClass)->newFromBuilder($attributes);
    }

    /**
     * @return array<string, mixed>
     */
    abstract protected function cachedRelationConfig(string $relation): array;
}
