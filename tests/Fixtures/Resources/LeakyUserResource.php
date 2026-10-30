<?php

declare(strict_types=1);

namespace Webong\Cogent\Tests\Fixtures\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Webong\Cogent\Concerns\PreparesResources;
use Webong\Cogent\Tests\Fixtures\Models\User;

/**
 * Renders posts it never declared, so they load lazily.
 *
 * @mixin User
 */
final class LeakyUserResource extends JsonResource
{
    use PreparesResources;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'posts' => $this->posts()->count(),
        ];
    }
}
