<?php

declare(strict_types=1);

namespace Webong\Fluent\Concerns;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection as SupportCollection;

/**
 * @template TModel of Model
 *
 * @mixin \Illuminate\Database\Eloquent\Collection<int, TModel>
 */
trait GraphRelations
{
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
                    $canonicalRelated = $this->canonicalRelationGraphModel($related, $canonical);
                    $model->setRelation($relation, $canonicalRelated);
                    $relatedModels[] = $canonicalRelated;

                    continue;
                }

                if ($related instanceof EloquentCollection || $related instanceof SupportCollection) {
                    $canonicalRelated = $related
                        ->filter(fn (mixed $item): bool => $item instanceof Model)
                        ->map(function (Model $item) use (&$canonical): Model {
                            return $this->canonicalRelationGraphModel($item, $canonical);
                        })
                        ->values();

                    if ($related instanceof EloquentCollection) {
                        $model->setRelation(
                            $relation,
                            $canonicalRelated->isNotEmpty()
                                ? $canonicalRelated->first()->newCollection($canonicalRelated->all())
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
    protected function relationGraphPathTree(array $relations): array
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
    protected function relationGraphModels(Model $model, string $relation): array
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
     * @param  list<Model>  $models
     * @return static
     */
    protected function newRelationGraphCollection(array $models): static
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
    protected function canonicalRelationGraphModel(Model $model, array &$canonical): Model
    {
        $key = $this->relationGraphIdentityKey($model);

        if (! isset($canonical[$key])) {
            $canonical[$key] = $model;
        }

        return $canonical[$key];
    }

    protected function relationGraphIdentityKey(Model $model): string
    {
        return $model->getMorphClass().':'.($model->getKey() ?? spl_object_id($model));
    }
}
