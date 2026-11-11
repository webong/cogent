<?php

declare(strict_types=1);

namespace Webong\Cogent\Attributes;

use Attribute;
use DateInterval;
use DateTimeInterface;

/**
 * Mark a relation method as cacheable.
 *
 * The relation itself stays a plain Eloquent relation: the key it caches under,
 * how long it lives and how a miss is resolved are the only things this adds.
 *
 * The `keyResolver` accepts a class-string of an invokable class that receives
 * the model and local key value at cache time, enabling dynamic cache keys:
 *
 *     #[CachedRelation(
 *         ttl: 86400,
 *         keyResolver: ConnectionCacheKeyResolver::class,
 *     )]
 *
 *     final class ConnectionCacheKeyResolver
 *     {
 *         public function __invoke(Model $model, mixed $id): string
 *         {
 *             return Connection::cacheKeyFor($model->getConnection()->getDatabaseName(), $id);
 *         }
 *     }
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class CachedRelation
{
    /**
     * @param  int|DateTimeInterface|DateInterval|null  $ttl  Null keeps the default of 86400 seconds.
     * @param  string|null  $key  A key template accepting {model}, {relation} and {value}.
     * @param  class-string<\Webong\Cogent\Contracts\CachedRelationResolver>|null  $resolver
     * @param  class-string<\Illuminate\Support\Collection>|null  $collection
     * @param  class-string|null  $keyResolver  Class-string of an invokable class receiving (Model $model, mixed $id): string.
     */
    public function __construct(
        public readonly int|DateTimeInterface|DateInterval|null $ttl = null,
        public readonly ?string $key = null,
        public readonly ?string $resolver = null,
        public readonly ?string $collection = null,
        public readonly ?string $keyResolver = null,
    ) {
    }
}
