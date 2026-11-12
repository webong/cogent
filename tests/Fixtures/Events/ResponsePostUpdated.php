<?php

declare(strict_types=1);

namespace Webong\Cogent\Tests\Fixtures\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Database\Eloquent\Collection;
use Webong\Cogent\Attributes\WithResponse;
use Webong\Cogent\Concerns\BroadcastsResources;
use Webong\Cogent\Concerns\SerializesPreparedModels;
use Webong\Cogent\Tests\Fixtures\Models\ResponsePost;

final class ResponsePostUpdated implements ShouldBroadcastNow
{
    use BroadcastsResources;
    use SerializesPreparedModels;

    /**
     * @param  Collection<int, ResponsePost>  $comments
     */
    public function __construct(
        #[WithResponse(appends: ['upper_title'], hidden: ['secret'])]
        public ResponsePost $post,
        #[WithResponse(visible: ['id', 'title'])]
        public ResponsePost $editor,
        /** @var Collection<int, ResponsePost> */
        public Collection $comments,
        public string $reason,
    ) {
    }

    public function broadcastAs(): string
    {
        return 'response.post.updated';
    }

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('posts.'.$this->post->id)];
    }
}
