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
            $attribute = $property->getAttributes(WithResource::class)[0] ?? null;

            if ($attribute === null || $property->isStatic() || ! $property->isInitialized($this)) {
                continue;
            }

            $value = $property->getValue($this);

            if ($value instanceof Model || $value instanceof EloquentCollection) {
                $preparer->prepare($value instanceof Model ? [$value] : $value, $attribute->newInstance()->class, $request, [], []);
            }
        }
    }

    /**
     * @param  ModelIdentifier  $value
     * @return Model
     */
    public function restoreModel($value)
    {
        // getClass() resolves morph aliases; Laravel 11 only has the class property.
        /** @phpstan-ignore function.alreadyNarrowedType */
        $class = method_exists($value, 'getClass') ? $value->getClass() : $value->class;

        $model = $this->getQueryForModelRestoration(
            (new $class)->setConnection($value->connection),
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
