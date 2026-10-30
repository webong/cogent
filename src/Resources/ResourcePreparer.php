<?php

declare(strict_types=1);

namespace Webong\Cogent\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Webong\Cogent\CogentCollection;
use Webong\Cogent\Support\CachedRelationRegistry;

/**
 * Runs a resource plan over the models a resource is about to render: one
 * batched load per relation and one grouped query per count, for the whole set.
 */
final class ResourcePreparer
{
    /**
     * @var array<string, bool>
     */
    private array $cached = [];

    public function __construct(
        private readonly CachedRelationRegistry $registry,
    ) {
    }

    /**
     * @param  iterable<mixed>  $models
     * @param  class-string  $resource
     * @param  list<class-string>  $chain  Resources already being prepared above this one.
     */
    public function prepare(iterable $models, string $resource, Request $request, array $chain = []): void
    {
        $plan = ResourcePlan::for($resource);

        if ($plan->isEmpty() || in_array($resource, $chain, true)) {
            return;
        }

        $chain[] = $resource;

        foreach ($this->groupByClass($models) as $group) {
            $this->applyIncludes($group, $plan->includes, $request, $chain);
            $this->applyCounts($group, $plan->counts);

            if ($plan->hasHook) {
                $resource::prepare($group, $request);
            }
        }
    }

    /**
     * @param  CogentCollection<Model>  $models
     * @param  array<string, class-string<JsonResource>|null>  $includes
     * @param  list<class-string>  $chain
     */
    private function applyIncludes(CogentCollection $models, array $includes, Request $request, array $chain): void
    {
        foreach ($this->includeTree($includes) as $relation => $branch) {
            $this->load($models, $relation);

            foreach ($this->groupByClass($models->related($relation)) as $class => $related) {
                $this->applyIncludes($related, $branch['children'], $request, $chain);

                $renderedBy = $branch['resource'] ?? ResourcePlan::resourceFor($class);

                if ($renderedBy !== null) {
                    $this->prepare($related, $renderedBy, $request, $chain);
                }
            }
        }
    }

    /**
     * @param  CogentCollection<Model>  $models
     * @param  list<string>  $counts
     */
    private function applyCounts(CogentCollection $models, array $counts): void
    {
        foreach ($counts as $relation) {
            $attribute = Str::snake($relation).'_count';

            $missing = $models->reject(
                fn (Model $model): bool => array_key_exists($attribute, $model->getAttributes()),
            );

            if ($missing->isEmpty()) {
                continue;
            }

            if ($missing->first()->{$relation}() instanceof HasOneOrMany) {
                $missing->batchCount($relation);
            } else {
                $missing->loadCount($relation);
            }
        }
    }

    /**
     * Load a relation the cheapest way the model allows: from the cache when it is
     * declared cacheable, otherwise with one batched query that shares instances.
     *
     * @param  CogentCollection<Model>  $models
     */
    private function load(CogentCollection $models, string $relation): void
    {
        if ($this->isCached($models->first()::class, $relation)) {
            $models->loadMissingCached($relation);

            return;
        }

        $models->includeMissing($relation);
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function isCached(string $model, string $relation): bool
    {
        $key = $model.'|'.$relation;

        if (array_key_exists($key, $this->cached)) {
            return $this->cached[$key];
        }

        try {
            $this->registry->for($model, $relation);

            return $this->cached[$key] = true;
        } catch (InvalidArgumentException) {
            return $this->cached[$key] = false;
        }
    }

    /**
     * Split relation paths on their first segment, keeping the resource mapped to each segment.
     *
     * @param  array<string, class-string<JsonResource>|null>  $includes
     * @return array<string, array{resource: class-string<JsonResource>|null, children: array<string, class-string<JsonResource>|null>}>
     */
    private function includeTree(array $includes): array
    {
        $tree = [];

        foreach ($includes as $path => $resource) {
            [$relation, $rest] = array_pad(explode('.', $path, 2), 2, null);

            $tree[$relation] ??= ['resource' => null, 'children' => []];

            if ($rest === null) {
                $tree[$relation]['resource'] = $resource ?? $tree[$relation]['resource'];
            } else {
                $tree[$relation]['children'][$rest] = $resource ?? $tree[$relation]['children'][$rest] ?? null;
            }
        }

        return $tree;
    }

    /**
     * Relations are read off the first model of a collection, so every group holds one model class.
     *
     * @param  iterable<mixed>  $models
     * @return array<class-string<Model>, CogentCollection<Model>>
     */
    private function groupByClass(iterable $models): array
    {
        $groups = [];

        foreach ($models as $model) {
            if ($model instanceof JsonResource) {
                $model = $model->resource;
            }

            if ($model instanceof Model) {
                $groups[$model::class][spl_object_id($model)] = $model;
            }
        }

        return array_map(fn (array $group): CogentCollection => new CogentCollection(array_values($group)), $groups);
    }
}
