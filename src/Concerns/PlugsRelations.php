<?php

declare(strict_types=1);

namespace Webong\Fluent\Concerns;

use Closure;
use DateTimeInterface;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Webong\Fluent\Support\CachedRelationAttributeStore;

trait PlugsRelations
{
    /**
     * Attach an already-known relation value to every model in the collection.
     *
     * @param  (Closure(Model, array-key): mixed)|mixed  $value
     */
    public function plug(string $relation, mixed $value): static
    {
        foreach ($this as $key => $model) {
            $model->setRelation($relation, $value instanceof Closure ? $value($model, $key) : $value);
        }

        return $this;
    }

    /**
     * Attach an already-known relation value to models that have not loaded it yet.
     *
     * @param  (Closure(Model, array-key): mixed)|mixed  $value
     */
    public function plugMissing(string $relation, mixed $value): static
    {
        foreach ($this as $key => $model) {
            if (! $model->relationLoaded($relation)) {
                $model->setRelation($relation, $value instanceof Closure ? $value($model, $key) : $value);
            }
        }

        return $this;
    }

    /**
     * Attach an already-known attribute value to every model in the collection.
     *
     * @param  (Closure(Model, array-key): mixed)|mixed  $value
     */
    public function plugAttribute(string $attribute, mixed $value): static
    {
        foreach ($this as $key => $model) {
            $model->setAttribute($attribute, $value instanceof Closure ? $value($model, $key) : $value);
        }

        return $this;
    }

    /**
     * Attach a morph relation from already-loaded Eloquent models.
     *
     * @param  iterable<array-key, mixed>  $candidates
     */
    public function plugMorph(
        string $relation,
        string $typeAttribute,
        string $keyAttribute,
        iterable $candidates,
    ): static {
        $index = $this->indexMorphCandidates($candidates);

        foreach ($this as $model) {
            $type = $model->getAttribute($typeAttribute);
            $key = $model->getAttribute($keyAttribute);

            if (! is_string($type) || $key === null) {
                continue;
            }

            if (isset($index[$type][(string) $key])) {
                $model->setRelation($relation, $index[$type][(string) $key]);
            }
        }

        return $this;
    }

    /**
     * Prepare accessor-backed attributes for serialization.
     *
     * @param  string|list<string>  $appends
     * @param  array<int|string, mixed>  $loadMissing
     * @param  array<int|string, mixed>  $methods
     */
    public function plugAppend(
        string|array $appends,
        array $loadMissing = [],
        array $methods = [],
    ): static {
        foreach ($methods as $method => $parameters) {
            if (is_int($method)) {
                $method = $parameters;
                $parameters = [];
            }

            $this->callOnEachModel((string) $method, is_array($parameters) ? array_values($parameters) : [$parameters]);
        }

        if ($loadMissing !== []) {
            $this->loadMissing($loadMissing);
        }

        foreach ($this as $model) {
            $model->append($appends);
        }

        return $this;
    }

    /**
     * Attach cached related models to every model in the collection.
     *
     * @param  class-string<Model>  $relatedClass
     * @param  Closure(list<mixed>): SupportCollection<array-key, covariant Model>  $resolver
     * @param  Closure(mixed): string  $cacheKey
     */
    public function plugCached(
        string $relation,
        string $localKey,
        string $relatedClass,
        Closure $resolver,
        Closure $cacheKey,
        DateTimeInterface|int $ttl = 86400,
    ): static {
        return $this->plugCachedRelation(
            relation: $relation,
            localKey: $localKey,
            relatedClass: $relatedClass,
            resolver: $resolver,
            cacheKey: $cacheKey,
            ttl: $ttl,
            missingOnly: false,
        );
    }

    /**
     * Attach cached related models only when the relation has not already been loaded.
     *
     * @param  class-string<Model>  $relatedClass
     * @param  Closure(list<mixed>): SupportCollection<array-key, covariant Model>  $resolver
     * @param  Closure(mixed): string  $cacheKey
     */
    public function plugCachedMissing(
        string $relation,
        string $localKey,
        string $relatedClass,
        Closure $resolver,
        Closure $cacheKey,
        DateTimeInterface|int $ttl = 86400,
    ): static {
        return $this->plugCachedRelation(
            relation: $relation,
            localKey: $localKey,
            relatedClass: $relatedClass,
            resolver: $resolver,
            cacheKey: $cacheKey,
            ttl: $ttl,
            missingOnly: true,
        );
    }

    /**
     * Attach cached collection relations to every model in the collection.
     *
     * @param  class-string<Model>  $relatedClass
     * @param  Closure(list<mixed>): SupportCollection<array-key, mixed>  $resolver
     * @param  Closure(mixed): string  $cacheKey
     * @param  class-string<SupportCollection>  $collectionClass
     */
    public function plugCachedCollection(
        string $relation,
        string $localKey,
        string $relatedClass,
        Closure $resolver,
        Closure $cacheKey,
        DateTimeInterface|int $ttl,
        string $collectionClass,
        bool $missingOnly = false,
    ): static {
        if ($this->isEmpty()) {
            return $this;
        }

        $models = $this->filter(function (Model $model) use ($relation, $missingOnly): bool {
            return ! $missingOnly || ! $model->relationLoaded($relation);
        });

        if ($models->isEmpty()) {
            return $this;
        }

        /** @var list<mixed> $localValues */
        $localValues = $models
            ->pluck($localKey)
            ->filter(static fn (mixed $value): bool => $value !== null)
            ->unique()
            ->values()
            ->all();

        if ($localValues === []) {
            return $this;
        }

        /** @var array<string, list<array<string, mixed>>> $attributeMap */
        $attributeMap = [];
        $missingValues = [];
        $requestStore = Container::getInstance()->make(CachedRelationAttributeStore::class);

        foreach ($localValues as $value) {
            $key = $cacheKey($value);
            $hit = $requestStore->has($key) ? $requestStore->get($key) : Cache::get($key);

            if (is_array($hit)) {
                $attributeMap[(string) $value] = $hit;
                continue;
            }

            $missingValues[] = $value;
        }

        if ($missingValues !== []) {
            $relatedGroups = $resolver($missingValues);

            foreach ($relatedGroups as $value => $relatedModels) {
                $attributes = collect($relatedModels)
                    ->filter(static fn (mixed $related): bool => $related instanceof Model)
                    ->map(static fn (Model $related): array => $related->getAttributes())
                    ->values()
                    ->all();

                Cache::put($cacheKey($value), $attributes, $ttl);
                $requestStore->put($cacheKey($value), $attributes);
                $attributeMap[(string) $value] = $attributes;
            }

            foreach ($missingValues as $value) {
                if (! array_key_exists((string) $value, $attributeMap)) {
                    Cache::put($cacheKey($value), [], $ttl);
                    $requestStore->put($cacheKey($value), []);
                    $attributeMap[(string) $value] = [];
                }
            }
        }

        $models->plug(
            $relation,
            function (Model $model) use ($relation, $localKey, $attributeMap, $relatedClass, $collectionClass): mixed {
                $localValue = $model->getAttribute($localKey);

                if ($localValue === null || ! array_key_exists((string) $localValue, $attributeMap)) {
                    return $model->relationLoaded($relation) ? $model->getRelation($relation) : new $collectionClass();
                }

                $relatedModels = array_map(
                    static fn (array $attributes): Model => (new $relatedClass)->newFromBuilder($attributes),
                    $attributeMap[(string) $localValue],
                );

                return new $collectionClass($relatedModels);
            },
        );

        return $this;
    }

    /**
     * Return already-loaded related models for the given relation path.
     */
    public function loaded(string $relation): EloquentCollection
    {
        return $this->newRelatedCollection(
            $this->collectLoadedRelationModels($this->all(), explode('.', $relation)),
        );
    }

    /**
     * Collapse duplicate already-loaded related model instances by class and key.
     */
    public function deduplicateLoadedRelation(string $relation): static
    {
        $canonical = [];

        foreach ($this as $model) {
            if (! $model->relationLoaded($relation)) {
                continue;
            }

            $related = $model->getRelation($relation);

            if ($related instanceof Model) {
                $model->setRelation($relation, $this->canonicalModel($related, $canonical));
                continue;
            }

            if ($related instanceof EloquentCollection) {
                $model->setRelation(
                    $relation,
                    $related->isNotEmpty()
                        ? $related->first()->newCollection(
                            $related
                                ->map(function (Model $item) use (&$canonical): Model {
                                    return $this->canonicalModel($item, $canonical);
                                })
                                ->all()
                        )
                        : $related,
                );
            }
        }

        return $this;
    }

    /**
     * @param  iterable<Model>  $models
     * @param  list<string>  $segments
     * @return list<Model>
     */
    private function collectLoadedRelationModels(iterable $models, array $segments): array
    {
        $relation = array_shift($segments);

        if ($relation === null) {
            return [];
        }

        $relatedModels = [];

        foreach ($models as $model) {
            array_push($relatedModels, ...$this->relationModels($model, $relation));
        }

        if ($segments === []) {
            return $relatedModels;
        }

        return $this->collectLoadedRelationModels($relatedModels, $segments);
    }

    /**
     * @return list<Model>
     */
    private function relationModels(Model $model, string $relation): array
    {
        if (! $model->relationLoaded($relation)) {
            return [];
        }

        $related = $model->getRelation($relation);

        if ($related instanceof Model) {
            return [$related];
        }

        if ($related instanceof EloquentCollection) {
            return $related->all();
        }

        if ($related instanceof SupportCollection) {
            return $related
                ->filter(fn (mixed $item): bool => $item instanceof Model)
                ->values()
                ->all();
        }

        return [];
    }

    /**
     * @param  list<Model>  $models
     */
    private function newRelatedCollection(array $models): EloquentCollection
    {
        $first = $models[0] ?? null;

        if ($first instanceof Model) {
            return $first->newCollection($models);
        }

        return new static();
    }

    /**
     * @param  list<mixed>  $parameters
     */
    private function callOnEachModel(string $method, array $parameters): void
    {
        foreach ($this as $model) {
            if (! method_exists($model, $method)) {
                throw new InvalidArgumentException(sprintf('Method [%s] does not exist on [%s].', $method, $model::class));
            }

            $model->{$method}(...$parameters);
        }
    }

    /**
     * @param  array<string, Model>  $canonical
     */
    private function canonicalModel(Model $model, array &$canonical): Model
    {
        $key = $this->modelIdentityKey($model);

        if (! isset($canonical[$key])) {
            $canonical[$key] = $model;
        }

        return $canonical[$key];
    }

    /**
     * @param  class-string<Model>  $relatedClass
     * @param  Closure(list<mixed>): SupportCollection<array-key, covariant Model>  $resolver
     * @param  Closure(mixed): string  $cacheKey
     */
    private function plugCachedRelation(
        string $relation,
        string $localKey,
        string $relatedClass,
        Closure $resolver,
        Closure $cacheKey,
        DateTimeInterface|int $ttl,
        bool $missingOnly,
    ): static {
        if ($this->isEmpty()) {
            return $this;
        }

        $models = $this->filter(function (Model $model) use ($relation, $missingOnly): bool {
            return ! $missingOnly || ! $model->relationLoaded($relation);
        });

        if ($models->isEmpty()) {
            return $this;
        }

        /** @var list<mixed> $localValues */
        $localValues = $models
            ->pluck($localKey)
            ->filter(static fn (mixed $value): bool => $value !== null)
            ->unique()
            ->values()
            ->all();

        if ($localValues === []) {
            return $this;
        }

        /** @var array<string, array<string, mixed>> $attributeMap */
        $attributeMap = [];
        $missingValues = [];
        $requestStore = Container::getInstance()->make(CachedRelationAttributeStore::class);

        foreach ($localValues as $value) {
            $key = $cacheKey($value);
            $hit = $requestStore->has($key) ? $requestStore->get($key) : Cache::get($key);

            if (is_array($hit)) {
                $attributeMap[(string) $value] = $hit;
                continue;
            }

            $missingValues[] = $value;
        }

        if ($missingValues !== []) {
            $related = $resolver($missingValues);

            foreach ($related as $value => $relatedModel) {
                $attributes = $relatedModel->getAttributes();
                Cache::put($cacheKey($value), $attributes, $ttl);
                $requestStore->put($cacheKey($value), $attributes);
                $attributeMap[(string) $value] = $attributes;
            }
        }

        /** @var array<string, Model> $sharedRelated */
        $sharedRelated = [];

        $models->plug(
            $relation,
            function (Model $model) use ($relation, $localKey, $attributeMap, $relatedClass, &$sharedRelated): mixed {
                $localValue = $model->getAttribute($localKey);

                if ($localValue === null || ! array_key_exists((string) $localValue, $attributeMap)) {
                    return $model->relationLoaded($relation) ? $model->getRelation($relation) : null;
                }

                $mapKey = (string) $localValue;

                if (! isset($sharedRelated[$mapKey])) {
                    $relatedModel = (new $relatedClass)->newFromBuilder($attributeMap[$mapKey]);
                    $sharedRelated[$mapKey] = $relatedModel;
                }

                return $sharedRelated[$mapKey];
            },
        );

        return $this;
    }

    private function modelIdentityKey(Model $model): string
    {
        return $model->getMorphClass().':'.($model->getKey() ?? spl_object_id($model));
    }

    /**
     * @param  iterable<array-key, mixed>  $candidates
     * @return array<string, array<string, Model>>
     */
    private function indexMorphCandidates(iterable $candidates): array
    {
        $index = [];

        foreach ($candidates as $candidate) {
            if ($candidate instanceof Model) {
                $key = (string) $candidate->getKey();

                $index[$candidate->getMorphClass()][$key] = $candidate;
                $index[$candidate::class][$key] = $candidate;

                continue;
            }

            if ($candidate instanceof EloquentCollection || $candidate instanceof SupportCollection) {
                $index = array_replace_recursive($index, $this->indexMorphCandidates($candidate->all()));

                continue;
            }

            if (is_iterable($candidate)) {
                $index = array_replace_recursive($index, $this->indexMorphCandidates($candidate));
            }
        }

        return $index;
    }
}
