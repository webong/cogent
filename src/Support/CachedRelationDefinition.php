<?php

declare(strict_types=1);

namespace Webong\Cogent\Support;

use Closure;
use DateInterval;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Webong\Cogent\Contracts\CachedRelationResolver;

/**
 * Everything needed to hydrate and to invalidate one cached relation.
 */
final readonly class CachedRelationDefinition
{
    /**
     * @param  class-string<Model>|null  $modelClass  The model the relation is declared on, when known.
     * @param  class-string<Model>  $relatedClass
     * @param  class-string<Collection>|null  $collectionClass
     * @param  string|null  $localKey  The attribute on the parent that holds the value a key is cached under.
     * @param  string|null  $relatedKey  The column on the related model a single value is looked up by.
     * @param  string|null  $relatedForeignKey  The column on the related model that points back at the parent.
     * @param  string|null  $relatedMorphType  The column on the related model that holds its parent type.
     * @param  string|null  $morphClass  The parent type related morph rows must match.
     * @param  Closure(mixed): string|string|null  $key
     * @param  Closure(Model, mixed): string|class-string|null  $keyResolver  Dynamic cache key resolver receiving (Model $model, mixed $id): string.
     * @param  Closure(Builder, list<mixed>): Collection<array-key, mixed>|class-string<CachedRelationResolver>|string|null  $resolver
     */
    public function __construct(
        public ?string $modelClass,
        public string $relation,
        public ?string $localKey,
        public string $relatedClass,
        public bool $many,
        public ?string $collectionClass = null,
        public ?string $relatedKey = null,
        public ?string $relatedForeignKey = null,
        public ?string $relatedMorphType = null,
        public ?string $morphClass = null,
        public Closure|string|null $key = null,
        public Closure|string|null $keyResolver = null,
        public int|DateTimeInterface|DateInterval $ttl = 86400,
        public Closure|string|null $resolver = null,
        public ?Builder $baseQuery = null,
    ) {
    }

    public function cacheKeyFor(mixed $localValue, ?Model $model = null): string
    {
        if ($this->keyResolver !== null && $model !== null) {
            $resolver = $this->resolveKeyResolver($this->keyResolver);

            return $resolver($model, $localValue);
        }

        if ($this->key instanceof Closure) {
            return ($this->key)($localValue);
        }

        return strtr($this->key ?? sprintf('cogent:%s:%s:{value}', $this->modelClass ?? 'model', $this->relation), [
            '{model}' => $this->modelClass ?? 'model',
            '{relation}' => $this->relation,
            '{value}' => (string) $localValue,
        ]);
    }

    /**
     * @param  list<mixed>  $localValues
     * @return Collection<array-key, mixed>
     */
    public function resolve(array $localValues): Collection
    {
        $query = $this->baseQuery ? clone $this->baseQuery : (new $this->relatedClass)->newQuery();
        $related = new $this->relatedClass;

        if ($this->relatedMorphType !== null && $this->morphClass !== null) {
            $query->where($this->relatedMorphType, $this->morphClass);
        }

        if ($this->resolver !== null) {
            return $this->resolverCallback()($query, $localValues);
        }

        if ($this->many) {
            return $this->resolveMany($query, $localValues);
        }

        return $this->relatedForeignKey !== null
            ? $this->resolveOnePerKey($query, $localValues)
            : $this->resolveOne($related, $query, $localValues);
    }

    /**
     * The values a model of the related class contributes to this cache, used to
     * forget exactly the keys a save or delete made stale.
     *
     * @return list<mixed>
     */
    public function localValuesFor(Model $related): array
    {
        if (! $this->many) {
            if ($this->relatedForeignKey !== null) {
                return array_values(array_filter([
                    $related->getAttribute($this->relatedForeignKey),
                    $related->getRawOriginal($this->relatedForeignKey),
                ], static fn (mixed $value): bool => $value !== null));
            }

            return [$related->getKey()];
        }

        if ($this->relatedForeignKey === null) {
            return [];
        }

        return array_values(array_filter([
            $related->getAttribute($this->relatedForeignKey),
            $related->getRawOriginal($this->relatedForeignKey),
        ], static fn (mixed $value): bool => $value !== null));
    }

    /**
     * @return Closure(Builder, list<mixed>): Collection<array-key, mixed>
     */
    private function resolverCallback(): Closure
    {
        $resolver = $this->resolver;

        if ($resolver instanceof Closure) {
            return $resolver;
        }

        if (! is_subclass_of($resolver, CachedRelationResolver::class)) {
            throw new InvalidArgumentException(sprintf(
                'The resolver [%s] for the cached relation [%s] must implement [%s].',
                $resolver,
                $this->relation,
                CachedRelationResolver::class,
            ));
        }

        return Closure::fromCallable([new $resolver, 'resolve']);
    }

    /**
     * Resolve the keyResolver into a callable (Model, mixed): string.
     *
     * @param  class-string|Closure  $keyResolver
     * @return Closure(Model, mixed): string
     */
    private function resolveKeyResolver(string|Closure $keyResolver): Closure
    {
        if ($keyResolver instanceof Closure) {
            return $keyResolver;
        }

        return Closure::fromCallable([new $keyResolver, '__invoke']);
    }

    /**
     * @param  list<mixed>  $localValues
     * @return Collection<array-key, mixed>
     */
    private function resolveOne(Model $related, Builder $query, array $localValues): Collection
    {
        $key = $this->relatedKey ?? $related->getKeyName();
        $this->stripForeignKeyWhere($query, $key);

        return $query
            ->whereIn($key, $localValues)
            ->get()
            ->keyBy(static fn (Model $model): string => (string) $model->getAttribute($key));
    }

    /**
     * @param  list<mixed>  $localValues
     * @return Collection<array-key, mixed>
     */
    private function resolveMany(Builder $query, array $localValues): Collection
    {
        $foreignKey = $this->requireForeignKey();
        $this->stripForeignKeyWhere($query, $foreignKey);

        return $query
            ->whereIn($foreignKey, $localValues)
            ->get()
            ->groupBy(static fn (Model $model): string => (string) $model->getAttribute($foreignKey));
    }

    /**
     * Remove any where constraint on the foreign key column from the query,
     * so a whereIn on multiple parent IDs can be applied instead.
     *
     * The base query from the declared relation already has a where on the
     * foreign key for a single parent; we replace it with whereIn for many parents.
     *
     * @param  string  $foreignKey  The column name, qualified or not.
     */
    private function stripForeignKeyWhere(Builder $query, string $foreignKey): void
    {
        $related = new $this->relatedClass;
        $qualified = $related->getTable().'.'.$foreignKey;

        $query->getQuery()->wheres = array_values(array_filter(
            $query->getQuery()->wheres,
            fn ($w) => ! isset($w['column']) || ! in_array($w['column'], [$foreignKey, $qualified], true),
        ));
    }

    /**
     * Resolve one model per key, dropping the keys that have no row so they
     * stay a cache miss instead of caching an empty model.
     *
     * @param  list<mixed>  $localValues
     * @return Collection<array-key, mixed>
     */
    private function resolveOnePerKey(Builder $query, array $localValues): Collection
    {
        $foreignKey = $this->requireForeignKey();

        return $this->resolveMany($query, $localValues)
            ->map(static fn (Collection $group): ?Model => $group->first())
            ->filter();
    }

    private function requireForeignKey(): string
    {
        if ($this->relatedForeignKey === null) {
            throw new InvalidArgumentException(sprintf(
                'The cached relation [%s] cannot derive its foreign key, pass foreignKey or a resolver to cached().',
                $this->relation,
            ));
        }

        return $this->relatedForeignKey;
    }
}
