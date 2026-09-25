<?php

declare(strict_types=1);

namespace Webong\Fluent\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * @template TModel of Model
 *
 * @mixin \Illuminate\Database\Eloquent\Collection<int, TModel>
 */
trait LoadsBatchRelationCounts
{
    /**
     * Batch-load {snake(relation)}_count onto models with one grouped query (same constraints as the relation).
     *
     * Uses {@see Relation::noConstraints()} and {@see Relation::addEagerConstraints()} like eager loading,
     * instead of per-row correlated subselects from {@see \Illuminate\Database\Eloquent\Builder::withCount()}.
     *
     * Supports {@see HasOneOrMany} (including {@see \Illuminate\Database\Eloquent\Relations\MorphOneOrMany}).
     *
     * @return $this
     */
    public function loadCounts(string $relationship): static
    {
        if ($this->isEmpty()) {
            return $this;
        }

        $first = $this->first();

        $attribute = Str::snake($relationship).'_count';

        /** @var Collection<int|string, int|string> $counts */
        $counts = Relation::noConstraints(function () use ($first, $relationship): Collection {
            /** @var Relation<Model, Model, mixed> $relation */
            $relation = $first->{$relationship}();

            if (! $relation instanceof HasOneOrMany) {
                throw new InvalidArgumentException(
                    sprintf('Relationship [%s] must be a has-one-or-many style relation.', $relationship),
                );
            }

            $relation->addEagerConstraints($this->all());

            $related = $relation->getRelated();
            $qualifiedForeignKey = $related->qualifyColumn($relation->getForeignKeyName());

            return $relation->getQuery()
                ->selectRaw($qualifiedForeignKey.' as batch_parent_id, COUNT(*) as aggregate')
                ->groupBy($qualifiedForeignKey)
                ->pluck('aggregate', 'batch_parent_id');
        });

        foreach ($this as $model) {
            $key = $model->getKey();
            $model->setAttribute(
                $attribute,
                (int) ($counts[$key] ?? $counts[(string) $key] ?? 0),
            );
        }

        return $this;
    }
}
