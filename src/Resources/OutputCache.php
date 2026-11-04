<?php

declare(strict_types=1);

namespace Webong\Cogent\Resources;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Webong\Cogent\Cache\CacheKeyBuilder;
use Webong\Cogent\Cache\SurfaceCache;

/**
 * Caches rendered resources, tagged with every model they rendered and every
 * foreign key a has-one-or-many relation or count read, so saving any of those
 * rows forgets the entry.
 *
 * Which columns of a model class matter is kept in the cache too, so a save in
 * any later request knows which tags to forget.
 */
final class OutputCache
{
    private const WATCH_PREFIX = 'cogent:output:watch:';

    private const PRIMARY_KEY = '@key';

    /**
     * Tags and watched columns recorded while an entry is built, or null outside a build.
     *
     * @var array{tags: array<string, true>, watch: array<class-string, array<string, true>>}|null
     */
    private ?array $recording = null;

    public function __construct(
        private readonly Repository $cache,
        private readonly SurfaceCache $surface,
        private readonly CacheKeyBuilder $keys,
    ) {
    }

    /**
     * @param  list<Model>  $models  The models being rendered, in order.
     * @param  array<string, mixed>  $vary  Anything else the output depends on.
     * @param  Closure(): array<mixed>  $build
     * @return array<mixed>
     */
    public function remember(string $resource, array $models, array $vary, int $ttl, Closure $build): array
    {
        $identities = implode(',', array_map(
            static fn (Model $model): string => $model::class.':'.$model->getKey(),
            $models,
        ));

        $key = $this->keys->build('cogent', 'output', $resource, ['models' => $identities, 'vary' => $vary]);

        return $this->surface->rememberUsing($key, $ttl, function () use ($models, $build): array {
            $outer = $this->recording;
            $this->recording = ['tags' => [], 'watch' => []];

            try {
                $value = $build();

                $this->tagGraph($models);

                $recorded = $this->recording;
            } finally {
                $this->recording = $outer;
            }

            $this->persistWatch($recorded['watch']);

            if ($this->recording !== null) {
                // A cached resource rendered inside another one: the outer entry depends on it too.
                $this->recording['tags'] += $recorded['tags'];

                foreach ($recorded['watch'] as $class => $columns) {
                    $this->recording['watch'][$class] = ($this->recording['watch'][$class] ?? []) + $columns;
                }
            }

            return [$value, array_keys($recorded['tags'])];
        })->value;
    }

    public function isRecording(): bool
    {
        return $this->recording !== null;
    }

    /**
     * Note a relation that was read for models while an entry is built, such as a
     * count, which leaves no related models behind to tag.
     *
     * @param  iterable<Model>  $models
     */
    public function record(iterable $models, Relation $relation): void
    {
        if ($this->recording === null || ! $relation instanceof HasOneOrMany) {
            return;
        }

        $related = $relation->getRelated()::class;
        $foreignKey = $relation->getForeignKeyName();
        $localKey = $relation->getLocalKeyName();

        foreach ($models as $model) {
            $this->tag($related, $foreignKey, $model->getAttribute($localKey));
        }
    }

    /**
     * Forget every entry that rendered the model or read a row through one of its watched columns.
     */
    public function forget(Model $model): void
    {
        $columns = $this->cache->get(self::WATCH_PREFIX.$model::class);

        if (! is_array($columns) || $columns === []) {
            return;
        }

        $tags = [];

        foreach (array_keys($columns) as $column) {
            $values = $column === self::PRIMARY_KEY
                ? [$model->getKey()]
                : [$model->getAttribute($column), $model->getOriginal($column)];

            foreach (array_unique(array_filter($values, static fn (mixed $value): bool => $value !== null)) as $value) {
                $tags[] = $this->tagName($model::class, $column, $value);
            }
        }

        $this->surface->invalidateTags($tags);
    }

    private function tag(string $class, string $column, mixed $value): void
    {
        if ($this->recording === null || $value === null) {
            return;
        }

        $this->recording['tags'][$this->tagName($class, $column, $value)] = true;
        $this->recording['watch'][$class][$column] = true;
    }

    private function tagName(string $class, string $column, mixed $value): string
    {
        return $column === self::PRIMARY_KEY
            ? 'cogent:output:'.$class.':'.$value
            : 'cogent:output:'.$class.'.'.$column.':'.$value;
    }

    /**
     * @param  array<class-string, array<string, true>>  $watch
     */
    private function persistWatch(array $watch): void
    {
        foreach ($watch as $class => $columns) {
            $known = $this->cache->get(self::WATCH_PREFIX.$class);
            $known = is_array($known) ? $known : [];

            if (array_diff_key($columns, $known) !== []) {
                $this->cache->forever(self::WATCH_PREFIX.$class, $known + $columns);
            }
        }
    }

    /**
     * Tag every model reachable from the rendered ones through loaded relations,
     * and the foreign keys of the has-one-or-many relations that reached them.
     *
     * @param  list<Model>  $models
     */
    private function tagGraph(array $models): void
    {
        $seen = [];
        $pending = $models;

        /** @var array<string, array{relation: Relation, models: list<Model>}> $relations */
        $relations = [];

        while (($model = array_pop($pending)) !== null) {
            if (isset($seen[spl_object_id($model)])) {
                continue;
            }

            $seen[spl_object_id($model)] = true;
            $this->tag($model::class, self::PRIMARY_KEY, $model->getKey());

            foreach ($model->getRelations() as $name => $related) {
                if (is_string($name) && method_exists($model, $name)) {
                    $relations[$model::class.'|'.$name] ??= ['relation' => $model->{$name}(), 'models' => []];
                    $relations[$model::class.'|'.$name]['models'][] = $model;
                }

                if ($related instanceof Model) {
                    $pending[] = $related;
                } elseif ($related instanceof EloquentCollection) {
                    array_push($pending, ...$related->all());
                }
            }
        }

        foreach ($relations as ['relation' => $relation, 'models' => $parents]) {
            if ($relation instanceof Relation) {
                $this->record($parents, $relation);
            }
        }
    }
}
