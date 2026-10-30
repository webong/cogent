<?php

declare(strict_types=1);

namespace Webong\Cogent\Tests\Fixtures\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Webong\Cogent\Attributes\Counts;
use Webong\Cogent\Attributes\Includable;
use Webong\Cogent\Attributes\Includes;
use Webong\Cogent\Concerns\PreparesResources;
use Webong\Cogent\Tests\Fixtures\Models\User;

/**
 * @mixin User
 */
#[Includes('tags')]
#[Includable('posts')]
#[Counts('posts')]
final class UserResource extends JsonResource
{
    use PreparesResources;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'tags' => $this->whenLoaded('tags', fn (): array => $this->tags->pluck('name')->all()),
            'posts_count' => $this->whenCounted('posts'),
            'posts' => $this->whenLoaded('posts', fn (): array => $this->posts->pluck('title')->all()),
        ];
    }
}
