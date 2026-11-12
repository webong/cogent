<?php

declare(strict_types=1);

namespace Webong\Cogent\Concerns;

use Illuminate\Container\Container;
use Illuminate\Contracts\Database\ModelIdentifier;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\SerializesModels;
use ReflectionClass;
use Webong\Cogent\Attributes\WithResource;
use Webong\Cogent\Attributes\WithResponse;
use Webong\Cogent\Resources\ResourcePreparer;

/**
 * A drop-in for Laravel's SerializesModels. Restored models load what their
 * resource renders, and the relations they had are read from the cache when
 * they are cacheable, instead of being queried again one by one.
 */
trait SerializesPreparedModels
{
    use SerializesModels {
        __unserialize as unserializeModels;
        restoreCollection as restoreModelCollection;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function __unserialize(array $values): void
    {
        $this->unserializeModels($values);

        $preparer = $this->cogentPreparer();
        $request = Container::getInstance()->make('request');

        foreach ((new ReflectionClass($this))->getProperties() as $property) {
            $resourceAttribute = $property->getAttributes(WithResource::class)[0] ?? null;
            $responseAttribute = $property->getAttributes(WithResponse::class)[0] ?? null;

            if ($resourceAttribute === null && $responseAttribute === null) {
                continue;
            }

            if ($property->isStatic() || ! $property->isInitialized($this)) {
                continue;
            }

            $value = $property->getValue($this);

            if (! ($value instanceof Model || $value instanceof EloquentCollection)) {
                continue;
            }

            $models = $value instanceof Model ? [$value] : $value;
            $resourceClass = null;

            if ($resourceAttribute !== null) {
                $resourceClass = $resourceAttribute->newInstance()->class;
            }

            if ($responseAttribute !== null) {
                $responseConfig = $responseAttribute->newInstance();

                foreach ($models as $model) {
                    if ($responseConfig->appends !== null) {
                        $model->setAppends($responseConfig->appends);
                    }

                    if ($responseConfig->hidden !== null) {
                        $model->setHidden($responseConfig->hidden);
                    }

                    if ($responseConfig->visible !== null) {
                        $model->setVisible($responseConfig->visible);
                    }
                }

                $resourceClass ??= $responseConfig->resource;
            }

            if ($resourceClass !== null) {
                $preparer->prepare($models, $resourceClass, $request, [], []);
            }
        }
    }

    /**
     * @param  ModelIdentifier  $value
     * @return Model
     */
    public function restoreModel($value)
    {
        $model = $this->getQueryForModelRestoration(
            (new ($value->getClass()))->setConnection($value->connection),
            $value->id,
        )->useWritePdo()->firstOrFail();

        $this->cogentPreparer()->restore([$model], $value->relations, Container::getInstance()->make('request'));

        return $model;
    }

    /**
     * @param  ModelIdentifier  $value
     * @return EloquentCollection<int, Model>
     */
    protected function restoreCollection($value)
    {
        $collection = $this->restoreModelCollection($value);

        $this->cogentPreparer()->restore($collection, $value->relations, Container::getInstance()->make('request'));

        return $collection;
    }

    private function cogentPreparer(): ResourcePreparer
    {
        return Container::getInstance()->make(ResourcePreparer::class);
    }
}
