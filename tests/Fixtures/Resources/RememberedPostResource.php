<?php

declare(strict_types=1);

namespace Webong\Cogent\Tests\Fixtures\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Webong\Cogent\Attributes\Counts;
use Webong\Cogent\Attributes\Includes;
use Webong\Cogent\Attributes\Remember;
use Webong\Cogent\Concerns\PreparesResources;
use Webong\Cogent\Tests\Fixtures\Models\Post;

/**
 * @mixin Post
 */
#[Remember(300)]
#[Includes(['author' => UserResource::class])]
#[Counts('comments')]
final class RememberedPostResource extends JsonResource
{
    use PreparesResources;

    /**
     * @return array<string, mixed>
     */
    public static function cacheVary(Request $request): array
    {
        return ['locale' => app()->getLocale()];
    }

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
