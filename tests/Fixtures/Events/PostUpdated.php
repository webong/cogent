<?php

declare(strict_types=1);

namespace Webong\Cogent\Tests\Fixtures\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Database\Eloquent\Collection;
use Webong\Cogent\Attributes\WithResource;
use Webong\Cogent\Concerns\BroadcastsResources;
use Webong\Cogent\Concerns\SerializesPreparedModels;
use Webong\Cogent\Tests\Fixtures\Models\Comment;
use Webong\Cogent\Tests\Fixtures\Models\Post;
use Webong\Cogent\Tests\Fixtures\Models\User;
use Webong\Cogent\Tests\Fixtures\Resources\PostResource;
use Webong\Cogent\Tests\Fixtures\Resources\UserResource;

final class PostUpdated implements ShouldBroadcastNow
{
    use BroadcastsResources;
    use SerializesPreparedModels;

    /**
     * @param  Collection<int, Comment>  $comments
     */
    public function __construct(
        #[WithResource(PostResource::class)] public Post $post,
        #[WithResource(UserResource::class)] public User $editor,
        public Collection $comments,
        public string $reason,
    ) {
    }

    public function broadcastAs(): string
    {
        return 'post.updated';
    }

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('posts.'.$this->post->id)];
    }
}
