<?php

declare(strict_types=1);

namespace Webong\Cogent\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Resolve the related models for a batch of cache keys that missed.
 */
interface CachedRelationResolver
{
    /**
     * @param  list<mixed>  $localValues
     * @return Collection<array-key, mixed>
     */
    public function resolve(Builder $query, array $localValues): Collection;
}
