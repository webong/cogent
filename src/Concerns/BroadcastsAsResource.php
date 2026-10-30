<?php

declare(strict_types=1);

namespace Webong\Cogent\Concerns;

use Illuminate\Database\Eloquent\Model;
use Webong\Cogent\Resources\ResourcePayload;
use Webong\Cogent\Resources\ResourcePlan;

/**
 * Broadcast a model's own events, from Laravel's BroadcastsEvents, as its
 * resource: #[UseResource] on the model or the App\Http\Resources naming convention.
 *
 * @mixin Model
 */
trait BroadcastsAsResource
{
    /**
     * @return array<mixed>|null
     */
    public function broadcastWith(string $event): ?array
    {
        $resource = ResourcePlan::guessResource(static::class);

        return $resource === null ? null : ResourcePayload::from($resource::make($this));
    }
}
