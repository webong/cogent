<?php

declare(strict_types=1);

namespace Webong\Fluent\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class EloquentBatchCounter
{
    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<int|string>  $parentIds
     * @param  (Closure(Builder<TModel>): (Builder<TModel>|void))|null  $constraints
     */
    public function count(
        Builder $query,
        string $groupBy,
        array $parentIds,
        ?int $cap = 100,
        ?Closure $constraints = null,
    ): BatchCountResult {
        if ($cap !== null && $cap < 0) {
            throw new InvalidArgumentException('$countCap must be null or a non-negative integer.');
        }

        if ($parentIds === []) {
            return BatchCountResult::empty();
        }

        $query = clone $query;
        $query->whereIn($groupBy, array_values(array_unique($parentIds, SORT_REGULAR)));

        if ($constraints !== null) {
            $constrained = $constraints($query);

            if ($constrained instanceof Builder) {
                $query = $constrained;
            }
        }

        $query->groupBy($groupBy);

        if ($cap === null) {
            $rows = $query
                ->selectRaw("{$groupBy} as batch_parent_id, COUNT(*) as aggregate, 0 as truncated")
                ->get();
        } else {
            $rows = $query
                ->selectRaw(
                    "{$groupBy} as batch_parent_id, LEAST(COUNT(*), ?) as aggregate, (COUNT(*) > ?) as truncated",
                    [$cap, $cap],
                )
                ->get();
        }

        /** @var Collection<int|string, int> $counts */
        $counts = new Collection;
        /** @var Collection<int|string, bool> $truncated */
        $truncated = new Collection;

        foreach ($rows as $row) {
            $key = (string) $row->getAttribute('batch_parent_id');
            $counts[$key] = (int) $row->getAttribute('aggregate');
            $truncated[$key] = (bool) $row->getAttribute('truncated');
        }

        return new BatchCountResult($counts, $truncated);
    }
}
