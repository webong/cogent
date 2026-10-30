<?php

declare(strict_types=1);

namespace Webong\Cogent\Tests\Fixtures\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Webong\Cogent\Attributes\Includable;
use Webong\Cogent\Concerns\PreparesResources;
use Webong\Cogent\Tests\Fixtures\Models\Post;

/**
 * @mixin Post
 */
#[Includable(['author' => UserResource::class], 'comments')]
final class IncludablePostResource extends JsonResource
{
    use PreparesResources;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'author' => new UserResource($this->whenLoaded('author')),
            'comments' => $this->whenLoaded('comments', fn (): array => $this->comments->pluck('body')->all()),
        ];
    }
}
