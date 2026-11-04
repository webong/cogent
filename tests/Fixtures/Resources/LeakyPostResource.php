<?php

declare(strict_types=1);

namespace Webong\Cogent\Tests\Fixtures\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Webong\Cogent\Attributes\Includes;
use Webong\Cogent\Concerns\PreparesResources;
use Webong\Cogent\Tests\Fixtures\Models\Post;

/**
 * Renders comments it never declared, so they load lazily.
 *
 * @mixin Post
 */
#[Includes(['author' => LeakyUserResource::class])]
final class LeakyPostResource extends JsonResource
{
    use PreparesResources;

    public static bool $leak = true;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'comments' => $this->when(self::$leak, fn (): int => $this->comments->count()),
            'author' => new LeakyUserResource($this->whenLoaded('author')),
        ];
    }
}
