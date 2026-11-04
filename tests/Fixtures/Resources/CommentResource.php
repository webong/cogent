<?php

declare(strict_types=1);

namespace Webong\Cogent\Tests\Fixtures\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Webong\Cogent\Attributes\Includes;
use Webong\Cogent\Concerns\PreparesResources;
use Webong\Cogent\Tests\Fixtures\Models\Comment;

/**
 * @mixin Comment
 */
#[Includes('post')]
final class CommentResource extends JsonResource
{
    use PreparesResources;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            'post_title' => $this->whenLoaded('post', fn (): string => $this->post->title),
        ];
    }
}
