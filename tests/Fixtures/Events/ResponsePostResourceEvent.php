<?php

declare(strict_types=1);

namespace Webong\Cogent\Tests\Fixtures\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Webong\Cogent\Attributes\WithResponse;
use Webong\Cogent\Concerns\BroadcastsResources;
use Webong\Cogent\Tests\Fixtures\Models\ResponsePost;
use Webong\Cogent\Tests\Fixtures\Resources\ResponsePostResource;

final class ResponsePostResourceEvent implements ShouldBroadcastNow
{
    use BroadcastsResources;

    public function __construct(
        #[WithResponse(resource: ResponsePostResource::class)]
        public ResponsePost $post,
    ) {
    }

    public function broadcastAs(): string
    {
        return 'response.post.resource';
    }

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('posts.'.$this->post->id)];
    }
}
