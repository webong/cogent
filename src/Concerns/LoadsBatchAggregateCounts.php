<?php

declare(strict_types=1);

namespace Webong\Fluent\Concerns;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Webong\Fluent\Support\BatchCountResult;
use Webong\Fluent\Support\EloquentBatchCounter;

/**
 * @template TModel of Model
 *
 * @mixin \Illuminate\Database\Eloquent\Collection<int, TModel>
 */
trait LoadsBatchAggregateCounts
{
    /**
     * @template TRelatedModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TRelatedModel>  $query
     * @param  (Closure(Builder<TRelatedModel>): (Builder<TRelatedModel>|void))|null  $constraints
     */
    public function loadBatchCount(
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
