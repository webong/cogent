<?php

declare(strict_types=1);

namespace Webong\Cogent\Tests\Fixtures\Models;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Database\Eloquent\Attributes\UseResource;
use Illuminate\Database\Eloquent\BroadcastsEvents;
use Webong\Cogent\Concerns\BroadcastsAsResource;
use Webong\Cogent\Tests\Fixtures\Resources\PostResource;

#[UseResource(PostResource::class)]
class BroadcastingPost extends Post
{
    use BroadcastsAsResource;
    use BroadcastsEvents;

    protected $table = 'posts';

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(string $event): array
    {
        return [new PrivateChannel('posts')];
    }
}
