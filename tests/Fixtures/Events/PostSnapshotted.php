<?php

declare(strict_types=1);

namespace Webong\Cogent\Tests\Fixtures\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Webong\Cogent\Attributes\WithResource;
use Webong\Cogent\Concerns\SnapshotsBroadcasts;
use Webong\Cogent\Tests\Fixtures\Models\Post;
use Webong\Cogent\Tests\Fixtures\Resources\PostResource;

final class PostSnapshotted implements ShouldBroadcast
{
    use SnapshotsBroadcasts;

    public function __construct(
        #[WithResource(PostResource::class)] public Post $post,
        public string $reason,
    ) {
    }

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('posts.'.$this->post->id)];
    }
}
