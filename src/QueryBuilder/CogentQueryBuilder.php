<?php

declare(strict_types=1);

namespace Webong\Cogent\QueryBuilder;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder as SpatieQueryBuilder;
use Webong\Cogent\Attributes\Filterable;
use Webong\Cogent\Attributes\Sortable;
use Webong\Cogent\Resources\ResourcePlan;

/**
 * A unified query builder for Cogent resources.
 *
 * Wraps spatie/laravel-query-builder so you don't have to use it directly.
 * Reads #[Filterable], #[Sortable], and #[Includable] from the resource class
 * and applies them automatically.
 *
 *     $posts = Post::interpret(PostResource::class)->get();
 *
 *     // The resource may be guessed from the model instead:
 *     $posts = Post::interpret()->get();
 *
 *     // Or start from the model's own query:
 *     $posts = Post::query()->interpret(PostResource::class)->get();
 *
 *     // GET /posts?filter[title]=Hello&sort=-created_at&include=author
 *
 * Combines with PreparesResources: spatie eager-loads includes, then
 * PreparesResources loads anything still missing and applies #[Counts].
 */
final class CogentQueryBuilder
{
    /**
     * @param  Model|Builder|string  $model  Eloquent model, builder, or model class name
     * @param  class-string|null  $resource  The resource class to read declarations from; guessed from the model when null
     * @param  Request|null  $request
     */
    public static function for(Model|Builder|string $model, ?string $resource = null, ?Request $request = null): static
    {
        return new static($model, $resource, $request);
    }

    /**
     * @param  Model|Builder|string  $model
     * @param  class-string|null  $resource
     * @param  Request|null  $request
     */
    private function __construct(
        private Model|Builder|string $model,
        private ?string $resource = null,
        private ?Request $request = null,
    ) {
    }

    /**
     * Execute the query and return an Eloquent collection.
     *
     * Relations declared with #[Includable] that the request asked for are
     * eager-loaded by spatie, then PreparesResources loads anything still
     * missing and applies #[Counts] when the resource resolves.
     */
    public function get(): EloquentCollection
    {
        $spatie = $this->buildSpatie();

        $models = $spatie->get();

        return new EloquentCollection($models->all());
    }

    /**
     * Execute the query and return a paginated result.
     */
    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        $spatie = $this->buildSpatie();

        return $spatie->paginate($perPage);
    }

    /**
     * Execute the query and return a cursor-paginated result.
     */
    public function cursorPaginate(int $perPage = 15): \Illuminate\Pagination\CursorPaginator
    {
        $spatie = $this->buildSpatie();

        return $spatie->cursorPaginate($perPage);
    }

    /**
     * Get the underlying query builder Cogent drives, for advanced customization.
     *
     * The returned builder is what actually runs the query; today that is a
     * spatie/laravel-query-builder QueryBuilder.
     */
    public function explainer(): SpatieQueryBuilder
    {
        return $this->buildSpatie();
    }

    /**
     * Build the spatie QueryBuilder with allowed filters, sorts, and includes
     * read from the resource's #[Filterable], #[Sortable], and #[Includable] attributes.
     */
    private function buildSpatie(): SpatieQueryBuilder
    {
        $subject = $this->resolveSubject();

        $spatie = SpatieQueryBuilder::for($subject, $this->request);

        $this->applyFilters($spatie);
        $this->applySorts($spatie);
        $this->applyIncludes($spatie);

        return $spatie;
    }

    private function resolveSubject(): Builder
    {
        if ($this->model instanceof EloquentModel) {
            return $this->model->newQuery();
        }

        if ($this->model instanceof Builder) {
            return $this->model;
        }

        // Assume it's a model class name
        $modelClass = $this->model;

        if (! class_exists($modelClass)) {
            throw new ModelNotFoundException("Model class [{$modelClass}] not found.");
        }

        if (! is_subclass_of($modelClass, EloquentModel::class)) {
            throw new ModelNotFoundException("Class [{$modelClass}] is not an Eloquent model.");
        }

        return $modelClass::query();
    }

    /**
     * The resource to read declarations from, guessed from the model when none
     * was given.
     *
     * @return class-string
     */
    private function resourceClass(): string
    {
        if ($this->resource !== null) {
            return $this->resource;
        }

        $model = $this->modelClass();

        $resource = ResourcePlan::guessResource($model);

        if ($resource === null) {
            throw new InvalidArgumentException(sprintf(
                'No resource found for model [%s]. Pass one explicitly, or declare it with #[UseResource] or the App\\Http\\Resources naming convention.',
                $model,
            ));
        }

        return $this->resource = $resource;
    }

    /**
     * @return class-string<Model>
     */
    private function modelClass(): string
    {
        if ($this->model instanceof EloquentModel) {
            return $this->model::class;
        }

        if ($this->model instanceof Builder) {
            return $this->model->getModel()::class;
        }

        /** @var class-string<Model> $model */
        $model = $this->model;

        return $model;
    }

    private function applyFilters(SpatieQueryBuilder $spatie): void
    {
        $reflection = new \ReflectionClass($this->resourceClass());
        $attribute = $reflection->getAttributes(Filterable::class)[0] ?? null;

        if ($attribute === null) {
            return;
        }

        $config = $attribute->newInstance();

        foreach ($config->fields as $field => $type) {
            $spatie->allowedFilters($this->makeFilter($field, $type));
        }
    }

    private function applySorts(SpatieQueryBuilder $spatie): void
    {
        $reflection = new \ReflectionClass($this->resourceClass());
        $attribute = $reflection->getAttributes(Sortable::class)[0] ?? null;

        if ($attribute === null) {
            return;
        }

        $config = $attribute->newInstance();

        foreach ($config->fields as $field => $direction) {
            $spatie->allowedSorts($this->makeSort($field, $direction));
        }
    }

    private function applyIncludes(SpatieQueryBuilder $spatie): void
    {
        $reflection = new \ReflectionClass($this->resourceClass());
        $attribute = $reflection->getAttributes(\Webong\Cogent\Attributes\Includable::class)[0] ?? null;

        if ($attribute === null) {
            return;
        }

        $config = $attribute->newInstance();

        foreach ($config->relations as $relation => $renderedBy) {
            $spatie->allowedIncludes($relation);
        }
    }

    /**
     * @param  string  $field
     * @param  string  $type  exact, partial, beginsWith, endsWith, belongsTo, scope
     * @return \Spatie\QueryBuilder\AllowedFilter
     */
    private function makeFilter(string $field, string $type): AllowedFilter
    {
        return match (mb_strtolower($type)) {
            'partial' => AllowedFilter::partial($field),
            'beginswith' => AllowedFilter::beginsWith($field),
            'endswith' => AllowedFilter::endsWith($field),
            'belongsto' => AllowedFilter::belongsTo($field),
            'scope' => AllowedFilter::scope($field),
            default => AllowedFilter::exact($field),
        };
    }

    /**
     * @param  string  $field
     * @param  string  $direction  asc or desc
     * @return \Spatie\QueryBuilder\AllowedSort
     */
    private function makeSort(string $field, string $direction): AllowedSort
    {
        $prefix = mb_strtolower($direction) === 'desc' ? '-' : '';

        return AllowedSort::field($prefix . $field);
    }

    /**
     * Read the declared filters, sorts, and includes from a resource class,
     * without building a spatie QueryBuilder.
     *
     * @return array{
     *     includes: array<string, class-string|null>,
     *     filters: array<string, string>,
     *     sorts: array<string, string>
     * }
     */
    public static function metadataFor(string $resource): array
    {
        $reflection = new \ReflectionClass($resource);

        return [
            'includes' => self::includableFrom($reflection),
            'filters' => self::filterableFrom($reflection),
            'sorts' => self::sortableFrom($reflection),
        ];
    }

    /**
     * @return array<string, class-string|null>
     */
    private static function includableFrom(\ReflectionClass $reflection): array
    {
        $attribute = $reflection->getAttributes(\Webong\Cogent\Attributes\Includable::class)[0] ?? null;

        if ($attribute === null) {
            return [];
        }

        $config = $attribute->newInstance();

        $result = [];

        foreach ($config->relations as $relation => $renderedBy) {
            $result[$relation] = $renderedBy;
        }

        return $result;
    }

    /**
     * @return array<string, string>
     */
    private static function filterableFrom(\ReflectionClass $reflection): array
    {
        $attribute = $reflection->getAttributes(Filterable::class)[0] ?? null;

        if ($attribute === null) {
            return [];
        }

        $config = $attribute->newInstance();

        $result = [];

        foreach ($config->fields as $field => $type) {
            $result[$field] = $type;
        }

        return $result;
    }

    /**
     * @return array<string, string>
     */
    private static function sortableFrom(\ReflectionClass $reflection): array
    {
        $attribute = $reflection->getAttributes(Sortable::class)[0] ?? null;

        if ($attribute === null) {
            return [];
        }

        $config = $attribute->newInstance();

        $result = [];

        foreach ($config->fields as $field => $direction) {
            $result[$field] = $direction;
        }

        return $result;
    }
}
