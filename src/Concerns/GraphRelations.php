<?php

declare(strict_types=1);

namespace Webong\Cogent\Concerns;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection as SupportCollection;

/**
 * Reads and shares the relation graph that is already in memory.
 *
 * Nothing here queries: every method either walks relations that are loaded or
 * collapses duplicate instances so one model is never held twice.
 *
 * @template TModel of Model
 *
 * @mixin \Illuminate\Database\Eloquent\Collection<int, TModel>
 */
trait GraphRelations
{
    /**
     * Collect every model reachable through an already loaded relation path.
     *
     * The current models are not part of the result.
     */
    public function related(string $relation): EloquentCollection
    {
        return $this->newRelatedCollection(
            $this->collectRelatedModels($this->all(), explode('.', $relation)),
        );
    }

    /**
     * Collapse duplicate already-loaded related model instances by class and key.
     *
     * Models keep their relations, only the instances behind them are shared, so
     * comparing or mutating one copy is visible everywhere it is loaded.
     * @return $this
     */
    public function shareRelation(string $relation): static
    {
        $canonical = [];

        foreach ($this as $model) {
            if (! $model->relationLoaded($relation)) {
                continue;
            }

            $related = $model->getRelation($relation);

            if ($related instanceof Model) {
                $model->setRelation($relation, $this->canonicalRelation($related, $canonical));

                continue;
            }

            if ($related instanceof EloquentCollection) {
                $model->setRelation(
                    $relation,
                    $related->isNotEmpty()
                        ? $this->newRelatedCollection(
                            $related
                                ->map(function (Model $item) use (&$canonical): Model {
                                    return $this->canonicalRelation($item, $canonical);
                                })
                                ->all()
                        )
                        : $related,
                );
            }
        }

        return $this;
    }

    /**
     * @param  iterable<mixed>  $models
     * @param  array<string, array<string, mixed>>  $tree
     * @param  array<string, Model>  $canonical
     */
    protected function includeMissingRelationsInGraph(iterable $models, array $tree, array &$canonical): void
    {
        if ($tree === []) {
            return;
        }

        $modelsByClass = collect($models)
            ->filter(fn (mixed $model): bool => $model instanceof Model)
            ->groupBy(fn (Model $model): string => $model::class);

        foreach ($modelsByClass as $modelsForClass) {
            $first = $modelsForClass->first();

            if (! $first instanceof Model) {
                continue;
            }

            $first->newCollection($modelsForClass->all())->loadMissing(array_keys($tree));
        }

        /** @var list<Model> $allModels */
        $allModels = $modelsByClass
            ->flatMap(fn (SupportCollection $modelsForClass): array => $modelsForClass->all())
            ->all();

        foreach ($tree as $relation => $children) {
            $relatedModels = [];

            foreach ($allModels as $model) {
                if (! $model->relationLoaded($relation)) {
                    continue;
                }

                $related = $model->getRelation($relation);

                if ($related instanceof Model) {
                    $canonicalRelated = $this->canonicalRelation($related, $canonical);
                    $model->setRelation($relation, $canonicalRelated);
                    $relatedModels[] = $canonicalRelated;

                    continue;
                }

                if ($related instanceof EloquentCollection || $related instanceof SupportCollection) {
                    $canonicalRelated = $related
                        ->filter(fn (mixed $item): bool => $item instanceof Model)
                        ->map(function (Model $item) use (&$canonical): Model {
                            return $this->canonicalRelation($item, $canonical);
                        })
                        ->values();

                    if ($related instanceof EloquentCollection) {
                        $model->setRelation(
                            $relation,
                            $canonicalRelated->isNotEmpty()
                                ? $this->newRelatedCollection($canonicalRelated->all())
                                : $related,
                        );
                    } else {
                        $model->setRelation($relation, $canonicalRelated);
                    }

                    array_push($relatedModels, ...$canonicalRelated->all());
                }
            }

            $this->includeMissingRelationsInGraph($relatedModels, $children, $canonical);
        }
    }

    /**
     * @param  list<string>  $relations
     * @return array<string, array<string, mixed>>
     */
    protected function relationPathTree(array $relations): array
    {
        /** @var array<string, array<string, mixed>> $tree */
        $tree = [];

        foreach ($relations as $relation) {
            $segments = explode('.', $relation);
            $cursor = &$tree;

            foreach ($segments as $segment) {
                if ($segment === '') {
                    continue;
                }

                if (! array_key_exists($segment, $cursor)) {
                    $cursor[$segment] = [];
                }

                $cursor = &$cursor[$segment];
            }

            unset($cursor);
        }

        return $tree;
    }

    /**
     * @return list<Model>
     */
    protected function relatedModels(Model $model, string $relation): array
    {
        if (! $model->relationLoaded($relation)) {
            return [];
        }

        $related = $model->getRelation($relation);

        if ($related instanceof Model) {
            return [$related];
        }

        if ($related instanceof EloquentCollection) {
            return $related->all();
        }

        if ($related instanceof SupportCollection) {
            return $related
                ->filter(fn (mixed $item): bool => $item instanceof Model)
                ->values()
                ->all();
        }

        return [];
    }

    /**
     * Build a collection of related models.
     *
     * Related models are usually of a different class than the collection they
     * were reached from, so the result is only guaranteed to be an Eloquent
     * collection.
     *
     * @param  list<Model>  $models
     * @return EloquentCollection<int, Model>
     */
    protected function newRelatedCollection(array $models): EloquentCollection
    {
        $first = $models[0] ?? null;

        if ($first instanceof Model) {
            return $first->newCollection($models);
        }

        return new static();
    }

    /**
     * Build a collection that keeps the type of the current collection.
     *
     * @param  list<Model>  $models
     * @return static
     */
    protected function newRelationCollection(array $models): static
    {
        $first = $models[0] ?? null;

        if ($first instanceof Model) {
            /** @var static $collection */
            $collection = $first->newCollection($models);

            return $collection;
        }

        return new static();
    }

    /**
     * @param  array<string, Model>  $canonical
     */
    protected function canonicalRelation(Model $model, array &$canonical): Model
    {
        $key = $this->relationIdentityKey($model);

        if (! isset($canonical[$key])) {
            $canonical[$key] = $model;
        }

        return $canonical[$key];
    }

    protected function relationIdentityKey(Model $model): string
    {
        return $model->getMorphClass().':'.($model->getKey() ?? spl_object_id($model));
    }

    /**
     * @param  iterable<Model>  $models
     * @param  list<string>  $segments
     * @return list<Model>
     */
    private function collectRelatedModels(iterable $models, array $segments): array
    {
        $relation = array_shift($segments);

        if ($relation === null) {
            return [];
        }

        $relatedModels = [];

        foreach ($models as $model) {
            array_push($relatedModels, ...$this->relatedModels($model, $relation));
        }

        if ($segments === []) {
            return $relatedModels;
        }

        return $this->collectRelatedModels($relatedModels, $segments);
    }
}
