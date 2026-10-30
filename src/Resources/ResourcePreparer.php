<?php

declare(strict_types=1);

namespace Webong\Cogent\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;
use InvalidArgumentException;
use WeakMap;
use Webong\Cogent\Cogent;
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

    /**
     * The resources each model was already prepared for. Nested resources are
     * resolved while the response is encoded, long after their parent prepared
     * them, and must not prepare again from the root request.
     *
     * @var WeakMap<Model, array<class-string, true>>
     */
    private WeakMap $prepared;

    public function __construct(
        private readonly CachedRelationRegistry $registry,
        private readonly OutputCache $output,
    ) {
        $this->prepared = new WeakMap;
    }

    /**
     * @param  iterable<mixed>  $models
     * @param  class-string  $resource
     * @param  list<class-string>  $chain  Resources already being prepared above this one.
     * @param  list<string>|null  $requested  The ?include= paths relative to this resource, read from the request when null.
     */
    public function prepare(iterable $models, string $resource, Request $request, array $chain = [], ?array $requested = null): void
    {
        $plan = ResourcePlan::for($resource);

        if ($plan->isEmpty() || in_array($resource, $chain, true)) {
            return;
        }

        $chain[] = $resource;
        $requested ??= Cogent::requestedIncludes($request);
        $includes = $plan->includesFor($requested);

        foreach ($this->groupByClass($models) as $group) {
            $group = $group->reject(fn (Model $model): bool => isset($this->prepared[$model][$resource]));

            if ($group->isEmpty()) {
                continue;
            }

            foreach ($group as $model) {
                $this->prepared[$model] = [...$this->prepared[$model] ?? [], $resource => true];
            }

            $this->applyIncludes($group, $includes, $request, $chain, $requested);
            $this->applyCounts($group, $plan->counts);

            if ($plan->hasHook) {
                $resource::prepare($group, $request);
            }
        }
    }

    /**
     * Load what restored models need: the plan of the resource that renders them,
     * then the relations they had when they were serialized, from the cache when
     * those are cacheable.
     *
     * @param  iterable<mixed>  $models
     * @param  list<string>  $relations
     */
    public function restore(iterable $models, array $relations, Request $request): void
    {
        foreach ($this->groupByClass($models) as $class => $group) {
            $resource = ResourcePlan::resourceFor($class);

            if ($resource !== null) {
                $this->prepare($group, $resource, $request, [], []);
            }

            $this->applyIncludes($group, array_fill_keys($relations, null), $request, [], []);
        }
    }

    /**
     * @param  CogentCollection<int, Model>  $models
     * @param  array<string, class-string<JsonResource>|null>  $includes
     * @param  list<class-string>  $chain
     * @param  list<string>  $requested
     */
    private function applyIncludes(CogentCollection $models, array $includes, Request $request, array $chain, array $requested): void
    {
        foreach ($this->includeTree($includes) as $relation => $branch) {
            $this->load($models, $relation);

            $nested = $this->below($requested, $relation);

            foreach ($this->groupByClass($models->related($relation)) as $class => $related) {
                $this->applyIncludes($related, $branch['children'], $request, $chain, $nested);

                $renderedBy = $branch['resource'] ?? ResourcePlan::resourceFor($class);

                if ($renderedBy !== null) {
                    $this->prepare($related, $renderedBy, $request, $chain, $nested);
                }
            }
        }
    }

    /**
     * The requested paths that continue past a relation, relative to it.
     *
     * @param  list<string>  $requested
     * @return list<string>
     */
    private function below(array $requested, string $relation): array
    {
        $nested = [];

        foreach ($requested as $path) {
            if (str_starts_with($path, $relation.'.')) {
                $nested[] = mb_substr($path, mb_strlen($relation) + 1);
            }
        }

        return $nested;
    }

    /**
     * @param  CogentCollection<int, Model>  $models
     * @param  list<string>  $counts
     */
    private function applyCounts(CogentCollection $models, array $counts): void
    {
        foreach ($counts as $relation) {
            $attribute = Str::snake($relation).'_count';

            $missing = $models->reject(
                fn (Model $model): bool => array_key_exists($attribute, $model->getAttributes()),
            );

            if ($this->output->isRecording()) {
                $this->output->record($models, $models->first()->{$relation}());
            }

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
     * @param  CogentCollection<int, Model>  $models
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
     * @return array<class-string<Model>, CogentCollection<int, Model>>
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
