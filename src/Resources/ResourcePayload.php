<?php

declare(strict_types=1);

namespace Webong\Cogent\Resources;

use Illuminate\Container\Container;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resolve a resource to the plain array a client receives, outside a response.
 */
final class ResourcePayload
{
    /**
     * @return array<mixed>
     */
    public static function from(JsonResource $resource): array
    {
        $resolved = $resource->resolve(Container::getInstance()->make('request'));

        // Nested resources resolve while they are encoded, the same as in a response.
        return json_decode(json_encode($resolved, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }
}
