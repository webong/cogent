<?php

declare(strict_types=1);

namespace Webong\Cogent\Attributes;

use Attribute;

/**
 * Name the resource that renders a property, when the model does not say it
 * with #[UseResource] or the naming convention:
 *
 *     public function __construct(#[WithResource(PostResource::class)] public Post $post) {}
 *
 * Optionally control appended and hidden attributes on the underlying model
 * before the resource is resolved, e.g. to strip out HTTP-only appends or hide
 * sensitive relations on broadcast/serialized payloads:
 *
 *     public function __construct(
 *         #[WithResource(ConversationResource::class, appends: [], hidden: ['lastMessage'])]
 *         public Conversation $conversation
 *     ) {}
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class WithResource
{
    /**
     * @param  class-string<\Illuminate\Http\Resources\Json\JsonResource>  $class
     * @param  list<string>|null  $appends  Appended attributes to set on the model before resolve.
     * @param  list<string>|null  $hidden  Hidden attributes to set on the model before resolve.
     */
    public function __construct(
        public readonly string $class,
        public readonly ?array $appends = null,
        public readonly ?array $hidden = null,
    ) {
    }
}
