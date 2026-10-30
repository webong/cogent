<?php

declare(strict_types=1);

namespace Webong\Cogent\Concerns;

use Illuminate\Container\Container;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use ReflectionClass;
use ReflectionProperty;
use Webong\Cogent\Attributes\WithResource;
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

        return $payload;
    }

    private function broadcastValue(ReflectionProperty $property): mixed
    {
        $value = $property->getValue($this);
        $resource = ($property->getAttributes(WithResource::class)[0] ?? null)?->newInstance()->class;

        if ($value instanceof Model) {
            $resource ??= ResourcePlan::guessResource($value::class);
            $value = $resource === null ? $value : $resource::make($value);
        } elseif ($value instanceof EloquentCollection && $value->first() instanceof Model) {
            $resource ??= ResourcePlan::guessResource($value->first()::class);
            $value = $resource === null ? $value : $resource::collection($value);
        }

        if ($value instanceof JsonResource) {
            $resolved = $value->resolve(Container::getInstance()->make('request'));

            // Nested resources resolve while they are encoded, the same as in a response.
            return json_decode(json_encode($resolved, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        }

        return $value instanceof Arrayable ? $value->toArray() : $value;
    }
}
