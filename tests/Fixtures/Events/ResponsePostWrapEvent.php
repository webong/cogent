<?php

declare(strict_types=1);

namespace Webong\Cogent\Tests\Fixtures\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Webong\Cogent\Attributes\WithResponse;
use Webong\Cogent\Concerns\BroadcastsResources;
use Webong\Cogent\Tests\Fixtures\Models\ResponsePost;

final class ResponsePostWrapEvent implements ShouldBroadcastNow
{
    use BroadcastsResources;

    public function __construct(
        #[WithResponse(wrap: 'data')]
        public ResponsePost $post,
    ) {
    }

    public function broadcastAs(): string
    {
        return 'response.post.wrap';
    }

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('posts.'.$this->post->id)];
    }
}
