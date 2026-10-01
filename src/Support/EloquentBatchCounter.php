<?php

declare(strict_types=1);

namespace Webong\Cogent\Support;

use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
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
        ?string $cacheKey = null,
        int|DateTimeInterface|null $ttl = null,
        ?string $cacheContext = null,
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

        if ($cacheKey !== null && $ttl !== null) {
            $identity = [
                'sql' => $query->toSql(),
                'bindings' => $query->getBindings(),
                'group_by' => $groupBy,
                'parent_ids' => array_values($parentIds),
                'cap' => $cap,
                'context' => $cacheContext,
            ];
            $cacheKey = 'cogent:batch-aggregate:'.$cacheKey.':'.hash('sha256', serialize($identity));

            /** @var BatchCountResult $result */
            $result = Cache::remember($cacheKey, $ttl, fn (): BatchCountResult => $this->fetchCounts($query, $groupBy, $cap));

            return $result;
        }

        return $this->fetchCounts($query, $groupBy, $cap);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     */
    private function fetchCounts(Builder $query, string $groupBy, ?int $cap): BatchCountResult
    {
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
