<?php

declare(strict_types=1);

namespace Webong\Cogent\Concerns;

use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Webong\Cogent\Attributes\Remember;
use Webong\Cogent\Cogent;
use Webong\Cogent\Resources\OutputCache;
use Webong\Cogent\Resources\PreparedResourceCollection;
use Webong\Cogent\Resources\RenderGuard;
use Webong\Cogent\Resources\ResourcePayload;
use Webong\Cogent\Resources\ResourcePlan;
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

    private bool $cogentNested = false;

    private ?Remember $cogentRemember = null;

    /**
     * Skip preparing and caching, because whoever built this resource already did.
     *
     * @return $this
     */
    public function markPrepared(): static
    {
        $this->cogentPrepared = true;
        $this->cogentNested = true;

        return $this;
    }

    /**
     * Cache what this resource renders for the given number of seconds, like #[Remember].
     * Each authenticated user gets their own entry unless it is shared.
     *
     * @return $this
     */
    public function remember(int $seconds, bool $shared = false): static
    {
        $this->cogentRemember = new Remember($seconds, $shared);

        return $this;
    }

    /**
     * @param  Request|null  $request
     * @return array<mixed>
     */
    public function resolve($request = null)
    {
        $request ??= Container::getInstance()->make('request');

        $remember = $this->cogentNested ? null : $this->rememberFor();
        $models = $remember === null ? null : $this->renderedModels();

        if ($remember === null || $models === null) {
            $this->prepareOnce($request);

            return RenderGuard::watch($this->collectedResource() ?? static::class, fn (): array => parent::resolve($request));
        }

        return Container::getInstance()->make(OutputCache::class)->remember(
            $this->cacheName(),
            $models,
            $this->varyFor($request, $remember),
            $remember->ttl,
            function () use ($request): array {
                $this->prepareOnce($request);

                return ResourcePayload::encode(RenderGuard::watch($this->collectedResource() ?? static::class, fn (): array => parent::resolve($request)));
            },
        );
    }

    /**
     * @param  mixed  $resource
     * @return PreparedResourceCollection
     */
    protected static function newCollection($resource)
    {
        return new PreparedResourceCollection($resource, static::class);
    }

    private function prepareOnce(Request $request): void
    {
        if (! $this->cogentPrepared) {
            $this->cogentPrepared = true;
            $this->prepareResources($request);
        }
    }

    private function rememberFor(): ?Remember
    {
        return $this->cogentRemember
            ?? ResourcePlan::for(static::class)->remember
            ?? (is_string($collects = $this->collectedResource()) ? ResourcePlan::for($collects)->remember : null);
    }

    /**
     * The models this resource renders, in order, or null when anything else is rendered.
     *
     * @return list<Model>|null
     */
    private function renderedModels(): ?array
    {
        $items = $this instanceof ResourceCollection ? $this->collection->all() : [$this->resource];
        $models = [];

        foreach ($items as $item) {
            $model = $item instanceof JsonResource ? $item->resource : $item;

            if (! $model instanceof Model || $model->getKey() === null) {
                return null;
            }

            $models[] = $model;
        }

        return $models === [] ? null : $models;
    }

    private function cacheName(): string
    {
        $collects = $this->collectedResource();

        return $collects === null ? static::class : static::class.'|'.$collects;
    }

    /**
     * What else the output depends on: the requested includes, the authenticated
     * user unless the output is shared, and whatever a static
     * cacheVary(Request $request) on the resource returns.
     *
     * @return array<string, mixed>
     */
    private function varyFor(Request $request, Remember $remember): array
    {
        $vary = ['includes' => Cogent::requestedIncludes($request)];

        if (! $remember->shared) {
            $user = $request->user();

            $vary['user'] = $user instanceof Authenticatable ? $user->getAuthIdentifier() : null;
        }

        foreach (array_unique(array_filter([static::class, $this->collectedResource()])) as $resource) {
            if (method_exists($resource, 'cacheVary')) {
                $vary[$resource] = $resource::cacheVary($request);
            }
        }

        return $vary;
    }

    /**
     * @return class-string|null
     */
    private function collectedResource(): ?string
    {
        if (! $this instanceof ResourceCollection) {
            return null;
        }

        $collects = $this->collects();

        return is_string($collects) && $collects !== static::class ? $collects : null;
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
