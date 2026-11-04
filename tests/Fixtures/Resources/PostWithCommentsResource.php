<?php

declare(strict_types=1);

namespace Webong\Cogent\Tests\Fixtures\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Webong\Cogent\Attributes\Includes;
use Webong\Cogent\CogentCollection;
use Webong\Cogent\Concerns\PreparesResources;
use Webong\Cogent\Tests\Fixtures\Models\Post;

/**
 * @mixin Post
 */
#[Includes('comments')]
final class PostWithCommentsResource extends JsonResource
{
    use PreparesResources;

    /**
     * @var list<array{models: int, path: string}>
     */
    public static array $prepared = [];

    /**
     * @param  CogentCollection<int, Post>  $posts
     */
    public static function prepare(CogentCollection $posts, Request $request): void
    {
        self::$prepared[] = ['models' => $posts->count(), 'path' => $request->path()];

        $posts->plugAttribute('headline', fn (Post $post): string => mb_strtoupper($post->title));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'headline' => $this->resource->getAttribute('headline'),
            'comments' => CommentResource::collection($this->whenLoaded('comments')),
        ];
    }
}
