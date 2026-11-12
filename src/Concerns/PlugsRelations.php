<?php

declare(strict_types=1);

namespace Webong\Cogent\Concerns;

use Closure;
use DateTimeInterface;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Webong\Cogent\Support\CachedRelationAttributeStore;

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
     * @param  Closure(mixed, Model): string  $cacheKey
     */
    public function plugCached(
        string $relation,
        string $localKey,
        string $relatedClass,
        Closure $resolver,
        $cacheKey,
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
     * @param  Closure(mixed, Model): string  $cacheKey
     */
    public function plugCachedMissing(
        string $relation,
        string $localKey,
        string $relatedClass,
        Closure $resolver,
        $cacheKey,
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
     * @param  Closure(mixed, Model): string  $cacheKey
     * @param  class-string<SupportCollection>  $collectionClass
     */
    public function plugCachedCollection(
        string $relation,
        string $localKey,
        string $relatedClass,
        Closure $resolver,
        $cacheKey,
        DateTimeInterface|int $ttl,
        string $collectionClass,
        bool $missingOnly = false,
    ): static {
        if ($this->isEmpty()) {
            return $this;
        }

        // Build a map from local value to model so dynamic key resolvers
        // can access the model when computing cache keys.
        $valueToModel = [];
        foreach ($this as $model) {
            if ($missingOnly && $model->relationLoaded($relation)) {
                continue;
            }
            $value = $model->getAttribute($localKey);
            if ($value !== null && ! array_key_exists((string) $value, $valueToModel)) {
                $valueToModel[(string) $value] = $model;
            }
        }

        /** @var list<mixed> $localValues */
        $localValues = array_keys($valueToModel);

        if ($localValues === []) {
            return $this;
        }

        /** @var array<string, list<array<string, mixed>>> $attributeMap */
        $attributeMap = [];
        $missingValues = [];
        $requestStore = Container::getInstance()->make(CachedRelationAttributeStore::class);

        foreach ($localValues as $value) {
            $model = $valueToModel[(string) $value] ?? null;
            $key = $cacheKey($value, $model);
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
                $model = $valueToModel[(string) $value] ?? null;
                $attributes = collect($relatedModels)
                    ->filter(static fn (mixed $related): bool => $related instanceof Model)
                    ->map(static fn (Model $related): array => $related->getAttributes())
                    ->values()
                    ->all();

                Cache::put($cacheKey($value, $model), $attributes, $ttl);
                $requestStore->put($cacheKey($value, $model), $attributes);
                $attributeMap[(string) $value] = $attributes;
            }

            foreach ($missingValues as $value) {
                if (! array_key_exists((string) $value, $attributeMap)) {
                    $model = $valueToModel[(string) $value] ?? null;
                    Cache::put($cacheKey($value, $model), [], $ttl);
                    $requestStore->put($cacheKey($value, $model), []);
                    $attributeMap[(string) $value] = [];
                }
            }
        }

        foreach ($this as $model) {
            if ($missingOnly && $model->relationLoaded($relation)) {
                continue;
            }

            $localValue = $model->getAttribute($localKey);

            if ($localValue === null || ! array_key_exists((string) $localValue, $attributeMap)) {
                $model->setRelation($relation, $model->relationLoaded($relation) ? $model->getRelation($relation) : new $collectionClass());
                continue;
            }

            $relatedModels = array_map(
                static fn (array $attributes): Model => (new $relatedClass)->newFromBuilder($attributes),
                $attributeMap[(string) $localValue],
            );

            $model->setRelation($relation, new $collectionClass($relatedModels));
        }

        return $this;
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
     * @param  class-string<Model>  $relatedClass
     * @param  Closure(list<mixed>): SupportCollection<array-key, covariant Model>  $resolver
     * @param  Closure(mixed): string  $cacheKey
     */
    private function plugCachedRelation(
        string $relation,
        string $localKey,
        string $relatedClass,
        Closure $resolver,
        $cacheKey,
        DateTimeInterface|int $ttl,
        bool $missingOnly,
    ): static {
        if ($this->isEmpty()) {
            return $this;
        }

        // Build a map from local value to model so dynamic key resolvers
        // can access the model when computing cache keys.
        $valueToModel = [];
        foreach ($this as $model) {
            if ($missingOnly && $model->relationLoaded($relation)) {
                continue;
            }
            $value = $model->getAttribute($localKey);
            if ($value !== null && ! array_key_exists((string) $value, $valueToModel)) {
                $valueToModel[(string) $value] = $model;
            }
        }

        /** @var list<mixed> $localValues */
        $localValues = array_keys($valueToModel);

        if ($localValues === []) {
            return $this;
        }

        /** @var array<string, array<string, mixed>> $attributeMap */
        $attributeMap = [];
        $missingValues = [];
        $requestStore = Container::getInstance()->make(CachedRelationAttributeStore::class);

        foreach ($localValues as $value) {
            $model = $valueToModel[(string) $value] ?? null;
            $key = $cacheKey($value, $model);
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
                $model = $valueToModel[(string) $value] ?? null;
                $attributes = $relatedModel->getAttributes();
                Cache::put($cacheKey($value, $model), $attributes, $ttl);
                $requestStore->put($cacheKey($value, $model), $attributes);
                $attributeMap[(string) $value] = $attributes;
            }

            foreach ($missingValues as $value) {
                if (! array_key_exists((string) $value, $attributeMap)) {
                    $model = $valueToModel[(string) $value] ?? null;
                    Cache::put($cacheKey($value, $model), [], $ttl);
                    $requestStore->put($cacheKey($value, $model), []);
                    $attributeMap[(string) $value] = [];
                }
            }
        }

        /** @var array<string, Model> $sharedRelated */
        $sharedRelated = [];

        foreach ($this as $model) {
            if ($missingOnly && $model->relationLoaded($relation)) {
                continue;
            }

            $localValue = $model->getAttribute($localKey);

            if ($localValue === null || ! array_key_exists((string) $localValue, $attributeMap)) {
                $model->setRelation($relation, $model->relationLoaded($relation) ? $model->getRelation($relation) : null);
                continue;
            }

            $mapKey = (string) $localValue;

            // Empty cached attributes mean there is no related model.
            if (empty($attributeMap[$mapKey])) {
                $model->setRelation($relation, null);
                continue;
            }

            if (! isset($sharedRelated[$mapKey])) {
                $relatedModel = (new $relatedClass)->newFromBuilder($attributeMap[$mapKey]);
                $sharedRelated[$mapKey] = $relatedModel;
            }

            $model->setRelation($relation, $sharedRelated[$mapKey]);
        }

        return $this;
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
