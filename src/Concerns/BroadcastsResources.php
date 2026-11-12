<?php

declare(strict_types=1);

namespace Webong\Cogent\Concerns;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use ReflectionClass;
use ReflectionProperty;
use Webong\Cogent\Attributes\WithResource;
use Webong\Cogent\Attributes\WithResponse;
use Webong\Cogent\Resources\BroadcastLimit;
use Webong\Cogent\Resources\ResourcePayload;
use Webong\Cogent\Resources\ResourcePlan;

/**
 * Broadcast an event's public properties the way Laravel does, but render
 * models and collections through their resources, so a client receives the
 * same shape over a websocket as over HTTP.
 */
trait BroadcastsResources
{
    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $payload = [];

        foreach ((new ReflectionClass($this))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic() || $property->getName() === 'broadcastQueue' || ! $property->isInitialized($this)) {
                continue;
            }

            $payload[$property->getName()] = $this->broadcastValue($property);
        }

        return BroadcastLimit::check($this, $payload);
    }

    private function broadcastValue(ReflectionProperty $property): mixed
    {
        $value = $property->getValue($this);
        $resourceAttribute = $property->getAttributes(WithResource::class)[0] ?? null;
        $responseAttribute = $property->getAttributes(WithResponse::class)[0] ?? null;

        $resourceConfig = $resourceAttribute?->newInstance();
        $responseConfig = $responseAttribute?->newInstance();

        $resourceClass = $responseConfig?->resource;

        if ($resourceConfig !== null) {
            $resourceClass = $resourceConfig->class;
        }

        if ($value instanceof Model) {
            $this->applyResourceConfig($value, $resourceConfig);
            $this->applyResponseConfig($value, $responseConfig);
            $resourceClass ??= ResourcePlan::guessResource($value::class);
            $value = $resourceClass === null ? $value : $resourceClass::make($value);
        } elseif ($value instanceof EloquentCollection && $value->first() instanceof Model) {
            foreach ($value as $model) {
                $this->applyResourceConfig($model, $resourceConfig);
                $this->applyResponseConfig($model, $responseConfig);
            }
            $resourceClass ??= ResourcePlan::guessResource($value->first()::class);
            $value = $resourceClass === null ? $value : $resourceClass::collection($value);
        }

        $result = $value instanceof JsonResource
            ? ResourcePayload::from($value)
            : ($value instanceof Arrayable ? $value->toArray() : $value);

        if ($responseConfig?->wrap !== null) {
            return [$responseConfig->wrap => $result];
        }

        return $result;
    }

    /**
     * Apply #[WithResource] appends/hidden configuration to a model.
     *
     * @param  Model  $model
     * @param  WithResource|null  $resourceConfig
     * @return void
     */
    private function applyResourceConfig(Model $model, ?WithResource $resourceConfig): void
    {
        if ($resourceConfig === null) {
            return;
        }

        if ($resourceConfig->appends !== null) {
            $model->setAppends($resourceConfig->appends);
        }

        if ($resourceConfig->hidden !== null) {
            $model->setHidden($resourceConfig->hidden);
        }
    }

    /**
     * Apply #[WithResponse] appends/hidden/visible configuration to a model.
     *
     * @param  Model  $model
     * @param  WithResponse|null  $responseConfig
     * @return void
     */
    private function applyResponseConfig(Model $model, ?WithResponse $responseConfig): void
    {
        if ($responseConfig === null) {
            return;
        }

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
}
