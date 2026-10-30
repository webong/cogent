<?php

declare(strict_types=1);

namespace Webong\Cogent\Concerns;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Webong\Cogent\Resources\PreparedResourceCollection;
use Webong\Cogent\Resources\ResourcePreparer;

/**
 * Load what a resource declares with #[Includes] and #[Counts] for every model
 * it renders, once, right before it is resolved.
 *
 * Works on a JsonResource, on the collections it builds through collection()
 * and toResourceCollection(), and on ResourceCollection subclasses.
 *
 * @mixin JsonResource
 */
trait PreparesResources
{
    private bool $cogentPrepared = false;

    /**
     * Skip preparing, because whoever built this resource already did.
     *
     * @return $this
     */
    public function markPrepared(): static
    {
        $this->cogentPrepared = true;

        return $this;
    }

    /**
     * @param  Request|null  $request
     * @return array<mixed>
     */
    public function resolve($request = null)
    {
        $request ??= Container::getInstance()->make('request');

        if (! $this->cogentPrepared) {
            $this->cogentPrepared = true;
            $this->prepareResources($request);
        }

        return parent::resolve($request);
    }

    /**
     * @param  mixed  $resource
     * @return PreparedResourceCollection
     */
    protected static function newCollection($resource)
    {
        return new PreparedResourceCollection($resource, static::class);
    }

    private function prepareResources(Request $request): void
    {
        $preparer = Container::getInstance()->make(ResourcePreparer::class);

        if (! $this instanceof ResourceCollection) {
            if ($this->resource instanceof Model) {
                $preparer->prepare([$this->resource], static::class, $request);
            }

            return;
        }

        $items = $this->collection;

        $preparer->prepare($items, static::class, $request);

        if (is_string($collects = $this->collects()) && $collects !== static::class) {
            $preparer->prepare($items, $collects, $request);
        }

        foreach ($items as $item) {
            if (is_object($item) && method_exists($item, 'markPrepared')) {
                $item->markPrepared();
            }
        }
    }
}
