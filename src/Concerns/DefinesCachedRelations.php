<?php

declare(strict_types=1);

namespace Webong\Cogent\Concerns;

use Illuminate\Container\Container;
use InvalidArgumentException;
use Webong\Cogent\Support\CachedRelationDefinition;
use Webong\Cogent\Support\CachedRelationRegistry;

/**
 * Marks a model as declaring cacheable relations.
 *
 * #[CachedRelation] and cached() work on any model, this trait only adds the
 * lookup and the invalidation hooks on top of them.
 */
trait DefinesCachedRelations
{
    /**
     * @throws InvalidArgumentException When the relation declares no cached configuration.
     */
    public function cachedDefinition(string $relation): CachedRelationDefinition
    {
        return $this->cachedRelationRegistry()->for($this::class, $relation);
    }

    public function cachedDefinitionFor(string $relation): ?CachedRelationDefinition
    {
        try {
            return $this->cachedDefinition($relation);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    protected function cachedRelationRegistry(): CachedRelationRegistry
    {
        return Container::getInstance()->make(CachedRelationRegistry::class);
    }
}
