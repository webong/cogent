<?php

declare(strict_types=1);

namespace Webong\Cogent\Concerns;

use Illuminate\Database\Eloquent\BroadcastableModelEventOccurred;
use Illuminate\Database\Eloquent\BroadcastsEvents;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Webong\Cogent\Broadcasting\BroadcastBatch;
use Webong\Cogent\Resources\BroadcastLimit;
use Webong\Cogent\Resources\ResourcePayload;
use Webong\Cogent\Resources\ResourcePlan;

/**
 * Laravel's BroadcastsEvents, sending each model event as the model's resource:
 * #[UseResource] on the model or the App\Http\Resources naming convention.
 *
 * Inside Cogent::batchBroadcasts(), events are held back and sent together, with
 * every model prepared in one pass.
 *
 * @mixin Model
 */
trait BroadcastsAsResource
{
    use BroadcastsEvents;

    /**
     * @return array<mixed>|null
     */
    public function broadcastWith(string $event): ?array
    {
        $resource = ResourcePlan::guessResource(static::class);

        return $resource === null ? null : BroadcastLimit::check($this, ResourcePayload::from($resource::make($this)));
    }

    /**
     * @param  BroadcastableModelEventOccurred  $instance
     * @param  string  $event
     * @param  mixed  $channels
     * @return mixed
     */
    protected function broadcastIfBroadcastChannelsExistForEvent($instance, $event, $channels = null)
    {
        if (! static::$isBroadcasting) {
            return null;
        }

        if (empty($this->broadcastOn($event)) && empty($channels)) {
            return null;
        }

        $instance = $instance->onChannels(Arr::wrap($channels));

        return BroadcastBatch::defer($instance) ? null : broadcast($instance);
    }
}
