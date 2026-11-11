<?php

declare(strict_types=1);

namespace Webong\Cogent\Support;

use Closure;
use DateInterval;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * The overrides a relation method declares, either through #[CachedRelation] or
 * through the cached() helper.
 *
 * The `keyResolver` accepts a Closure or class-string of an invokable class that
 * receives the model and local key value at cache time, enabling dynamic cache
 * keys:
 *
 *     cached($relation, keyResolver: fn (Model $model, mixed $id): string => ...)
 */
final readonly class CachedRelationOverrides
{
    /**
     * @param  Closure(mixed): string|string|null  $key  Null derives the key from the relation.
     * @param  Closure(\Illuminate\Database\Eloquent\Builder, list<mixed>): \Illuminate\Support\Collection<array-key, mixed>|string|null  $resolver
     * @param  Closure(Model, mixed): string|class-string|null  $keyResolver  Dynamic cache key resolver receiving (Model $model, mixed $id): string.
     */
    public function __construct(
        public ?string $localKey = null,
        public ?string $foreignKey = null,
        public Closure|string|null $key = null,
        public int|DateTimeInterface|DateInterval|null $ttl = null,
        public Closure|string|null $resolver = null,
        public ?string $collection = null,
        public Closure|string|null $keyResolver = null,
    ) {
    }

    public static function none(): self
    {
        return new self;
    }

    public function isEmpty(): bool
    {
        return $this->localKey === null
            && $this->foreignKey === null
            && $this->key === null
            && $this->ttl === null
            && $this->resolver === null
            && $this->collection === null
            && $this->keyResolver === null;
    }

    public function merge(?self $previous): self
    {
        if ($previous === null || $previous->isEmpty()) {
            return $this;
        }

        return new self(
            localKey: $this->localKey ?? $previous->localKey,
            foreignKey: $this->foreignKey ?? $previous->foreignKey,
            key: $this->key ?? $previous->key,
            ttl: $this->ttl ?? $previous->ttl,
            resolver: $this->resolver ?? $previous->resolver,
            collection: $this->collection ?? $previous->collection,
            keyResolver: $this->keyResolver ?? $previous->keyResolver,
        );
    }
}
