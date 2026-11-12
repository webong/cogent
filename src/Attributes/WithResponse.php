<?php

declare(strict_types=1);

namespace Webong\Cogent\Attributes;

use Attribute;

/**
 * Control how a property is rendered into an HTTP JSON response, separately
 * from how it is cached (`#[CachedRelation]`) or which resource renders it
 * (`#[WithResource]`).
 *
 * Response shape options are orthogonal to caching: they affect what the
 * client sees, not what is stored or how it is invalidated.
 *
 *     public function __construct(
 *         #[WithResponse(
 *             resource: PostResponse::class,
 *             wrap: 'data',
 *             appends: [],
 *             hidden: ['secret'],
 *             visible: ['id', 'title'],
 *         )]
 *         public Post $post
 *     ) {}
 *
 * Applied by {@see \Webong\Cogent\Concerns\BroadcastsResources} when building
 * broadcast payloads and by {@see \Webong\Cogent\Concerns\SerializesPreparedModels}
 * when restoring models from the queue.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class WithResponse
{
    /**
     * @param  class-string<\Illuminate\Http\Resources\Json\JsonResource>|null  $resource  Overrides the resource guessed by #[WithResource] or ResourcePlan.
     * @param  string|null  $wrap  Wrap the resolved resource under this key (e.g. 'data').
     * @param  array<string, mixed>|null  $with  Additional data merged into the response.
     * @param  list<string>|null  $appends  Appended attributes to set on the model before resolve.
     * @param  list<string>|null  $hidden  Hidden attributes to set on the model before resolve.
     * @param  list<string>|null  $visible  Visible attributes to set on the model before resolve.
     */
    public function __construct(
        public readonly ?string $resource = null,
        public readonly ?string $wrap = null,
        public readonly ?array $with = null,
        public readonly ?array $appends = null,
        public readonly ?array $hidden = null,
        public readonly ?array $visible = null,
    ) {
    }
}
