<?php

declare(strict_types=1);

namespace Webong\Cogent\Support;

use Closure;
use DateInterval;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Adds cached() to every Eloquent relation, so a relation can declare how it is
 * cached in the same statement that builds it.
 */
final class CachedRelationMacro
{
    public static function register(): void
    {
        if (Relation::hasMacro('cached')) {
            return;
        }

        Relation::macro('cached', self::closure());
    }

    /**
     * The macro itself, bound to the relation it is called on.
     *
     * Exposed so static analysis reads the same signature, which is why this
     * file is excluded from PHPStan: $this only exists once the macro is bound.
     *
     * @return Closure(): Relation
     */
    public static function closure(): Closure
    {
        /**
         * @param  int|DateTimeInterface|DateInterval|null  $ttl
         * @param  string|Closure(mixed): string|null  $key
         * @param  string|Closure|null  $resolver
         * @param  class-string<\Illuminate\Support\Collection>|null  $collection
         * @return static
         */
        return function (
            int|DateTimeInterface|DateInterval|null $ttl = null,
            string|Closure|null $key = null,
            string|Closure|null $resolver = null,
            ?string $collection = null,
            ?string $localKey = null,
            ?string $foreignKey = null,
        ): Relation {
            CachedRelationCollector::push(new CachedRelationOverrides(
                localKey: $localKey,
                foreignKey: $foreignKey,
                key: $key,
                ttl: $ttl,
                resolver: $resolver,
                collection: $collection,
            ));

            return $this;
        };
    }
}
