<?php

declare(strict_types=1);

namespace Webong\Fluent\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * @template TModel of Model
 *
 * @mixin \Illuminate\Database\Eloquent\Collection<int, TModel>
 */
trait IncludesRelations
{
    use GraphRelations;

    /**
     * Include models reachable through already-loaded relations in a new graph collection.
     *
     * This does not query; it only follows relation edges that are already present in memory.
     *
     * @return static
     */
    public function include(string ...$relations): static
    {
        $models = [];
        $seen = [];

        foreach ($this as $model) {
            $key = $this->relationIdentityKey($model);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $models[] = $model;
        }

        $pushRelated = function (Model $model) use (&$pushRelated, &$models, &$seen, $relations): void {
            foreach ($relations as $relation) {
                foreach ($this->relatedModels($model, $relation) as $related) {
                    $key = $this->relationIdentityKey($related);

                    if (isset($seen[$key])) {
                        continue;
                    }

                    $seen[$key] = true;
                    $models[] = $related;

                    $pushRelated($related);
                }
            }
        };

        foreach ($this as $model) {
            $pushRelated($model);
        }

        return $this->newRelationCollection($models);
    }

    /**
     * Include missing relation paths across this graph while sharing duplicate model instances.
     *
     * This may query like {@see \Illuminate\Database\Eloquent\Collection::loadMissing()}.
     *
     * @param  string|list<string>  $relations
     * @return $this
     */
    public function includeMissing(string|array $relations): static
    {
        if ($this->isEmpty()) {
            return $this;
        }

        $tree = $this->relationPathTree((array) $relations);
        $canonical = [];

        foreach ($this as $model) {
            $canonical[$this->relationIdentityKey($model)] = $model;
        }

        $this->includeMissingRelationsInGraph($this->all(), $tree, $canonical);

        return $this;
    }

    /**
     * Include morph relations, then load nested relations only for matching morph classes.
     *
     * @param  string|list<string>  $morphRelations
     * @param  array<class-string<Model>, array<int|string, mixed>|string>  $relationsByClass
     * @return $this
     */
    public function includeMissingMorph(string|array $morphRelations, array $relationsByClass): static
    {
        $morphRelations = (array) $morphRelations;

        $this->includeMissing($morphRelations);

        $relatedModelsByClass = collect($this->all())
            ->flatMap(function (Model $model) use ($morphRelations): array {
                $relatedModels = [];

                foreach ($morphRelations as $relation) {
                    array_push($relatedModels, ...$this->relatedModels($model, $relation));
                }

                return $relatedModels;
            })
            ->unique(fn (Model $model): string => $this->relationIdentityKey($model))
            ->groupBy(fn (Model $model): string => $model::class);

        foreach ($relationsByClass as $modelClass => $relations) {
            $models = $relatedModelsByClass->get($modelClass);

            if ($models === null || $models->isEmpty()) {
                continue;
            }

            /** @var Model $first */
            $first = $models->first();
            $first->newCollection($models->all())->loadMissing($relations);
        }

        return $this;
    }
}
