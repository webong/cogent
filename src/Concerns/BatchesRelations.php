<?php

declare(strict_types=1);

namespace Webong\Fluent\Concerns;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Webong\Fluent\Support\BatchCountResult;
use Webong\Fluent\Support\EloquentBatchCounter;

/**
 * Batch-loads counts onto every model in the collection with a single grouped query.
 *
 * @template TModel of Model
 *
 * @mixin \Illuminate\Database\Eloquent\Collection<int, TModel>
 */
trait BatchesRelations
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
    public function batchCount(string $relationship): static
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

    /**
     * Batch-load a count from any query onto every model, grouped by a column.
     *
     * Pass a cap to clamp the value, and truncatedAttribute to record which counts
     * were clamped so a UI can show "100+".
     *
     * @template TRelatedModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TRelatedModel>  $query
     * @param  (Closure(Builder<TRelatedModel>): (Builder<TRelatedModel>|void))|null  $constraints
     */
    public function batchAggregateCount(
        string $attribute,
        Builder $query,
        string $groupBy,
        ?int $cap = 100,
        ?Closure $constraints = null,
        ?string $truncatedAttribute = null,
    ): BatchCountResult {
        if ($cap !== null && $cap < 0) {
            throw new InvalidArgumentException('$countCap must be null or a non-negative integer.');
        }

        if ($this->isEmpty()) {
            return BatchCountResult::empty();
        }

        /** @var Model $first */
        $first = $this->first();

        /** @var list<int|string> $ids */
        $ids = $this->pluck($first->getKeyName())
            ->filter(static fn (mixed $id): bool => $id !== null)
            ->uniqueStrict()
            ->values()
            ->all();

        if ($ids === []) {
            $result = BatchCountResult::empty();
            $this->applyBatchCountResult($attribute, $result, $cap, $truncatedAttribute);

            return $result;
        }

        $result = Container::getInstance()->make(EloquentBatchCounter::class)->count(
            query: $query,
            groupBy: $query->getModel()->qualifyColumn($groupBy),
            parentIds: $ids,
            cap: $cap,
            constraints: $constraints,
        );

        $this->applyBatchCountResult($attribute, $result, $cap, $truncatedAttribute);

        return $result;
    }

    protected function applyBatchCountResult(
        string $attribute,
        BatchCountResult $result,
        ?int $cap,
        ?string $truncatedAttribute = null,
    ): void {
        foreach ($this as $model) {
            /** @var Model $model */
            $key = $model->getKey();
            if ($key === null) {
                $model->setAttribute($attribute, 0);

                if ($cap !== null && $truncatedAttribute !== null) {
                    $this->mergeBatchCountTruncatedFlag($model, $truncatedAttribute, $attribute, false);
                }

                continue;
            }

            $model->setAttribute($attribute, $result->countFor($key));

            if ($cap !== null && $truncatedAttribute !== null) {
                $this->mergeBatchCountTruncatedFlag($model, $truncatedAttribute, $attribute, $result->isTruncated($key));
            }
        }
    }

    private function mergeBatchCountTruncatedFlag(Model $model, string $truncatedAttribute, string $field, bool $truncated): void
    {
        $existing = $model->getAttribute($truncatedAttribute);
        /** @var array<string, bool> $map */
        $map = is_array($existing) ? $existing : [];
        $map[$field] = $truncated;
        $model->setAttribute($truncatedAttribute, $map);
    }
}
