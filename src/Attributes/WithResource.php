<?php

declare(strict_types=1);

namespace Webong\Cogent\Attributes;

use Attribute;

/**
 * Name the resource that renders a property, when the model does not say it
 * with #[UseResource] or the naming convention:
 *
 *     public function __construct(#[WithResource(PostResource::class)] public Post $post) {}
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class WithResource
{
    /**
     * @param  class-string<\Illuminate\Http\Resources\Json\JsonResource>  $class
     */
    public function __construct(
        public readonly string $class,
    ) {
    }
}
