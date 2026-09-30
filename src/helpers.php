<?php

declare(strict_types=1);

namespace Webong\Cogent;

use Closure;
use DateInterval;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Relations\Relation;
use Webong\Cogent\Support\CachedRelationCollector;
use Webong\Cogent\Support\CachedRelationOverrides;

if (! function_exists('Webong\Cogent\cached')) {
    /**
     * Mark a relation method as cacheable without an attribute.
     *
     * The same as calling cached() on the relation itself, kept for the times a
     * relation is built before it is returned.
     *
     * @template TRelation of Relation
     *
     * @param  TRelation  $relation
     * @param  string|null  $localKey  The attribute on this model the key is cached under.
     * @param  string|null  $foreignKey  The column on the related model that points back at this one.
     * @param  string|Closure(mixed): string|null  $key  A key template accepting {model}, {relation} and {value}.
     * @param  class-string<\Webong\Cogent\Contracts\CachedRelationResolver>|Closure|null  $resolver
     * @param  class-string<\Illuminate\Support\Collection>|null  $collection
     * @return TRelation
     */
    function cached(
        Relation $relation,
        ?string $localKey = null,
        ?string $foreignKey = null,
        string|Closure|null $key = null,
        int|DateTimeInterface|DateInterval|null $ttl = null,
        string|Closure|null $resolver = null,
        ?string $collection = null,
    ): Relation {
        CachedRelationCollector::push(new CachedRelationOverrides(
            localKey: $localKey,
            foreignKey: $foreignKey,
            key: $key,
            ttl: $ttl,
            resolver: $resolver,
            collection: $collection,
        ));

        return $relation;
    }
}
