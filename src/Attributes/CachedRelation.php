<?php

declare(strict_types=1);

namespace Webong\Fluent\Attributes;

use Attribute;
use DateInterval;
use DateTimeInterface;

/**
 * Mark a relation method as cacheable.
 *
 * The relation itself stays a plain Eloquent relation: the key it caches under,
 * how long it lives and how a miss is resolved are the only things this adds.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class CachedRelation
{
    /**
     * @param  int|DateTimeInterface|DateInterval|null  $ttl  Null keeps the default of 86400 seconds.
     * @param  string|null  $key  A key template accepting {model}, {relation} and {value}.
     * @param  class-string<\Webong\Fluent\Contracts\CachedRelationResolver>|null  $resolver
     * @param  class-string<\Illuminate\Support\Collection>|null  $collection
     */
    public function __construct(
        public readonly int|DateTimeInterface|DateInterval|null $ttl = null,
        public readonly ?string $key = null,
        public readonly ?string $resolver = null,
        public readonly ?string $collection = null,
    ) {
    }
}
