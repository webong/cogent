<?php

declare(strict_types=1);

namespace Webong\Cogent\Tests\Fixtures\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Webong\Cogent\Attributes\Counts;
use Webong\Cogent\Attributes\Includes;
use Webong\Cogent\Concerns\PreparesResources;
use Webong\Cogent\Tests\Fixtures\Models\Post;

/**
 * @mixin Post
 */
#[Includes(['author' => UserResource::class])]
#[Counts('comments')]
final class BroadcastLeakyPostResource extends JsonResource
{
    use PreparesResources;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'author' => new UserResource($this->whenLoaded('author')),
            'comments_count' => $this->whenCounted('comments'),
        ];
    }
}
