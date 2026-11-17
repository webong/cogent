<?php

declare(strict_types=1);

namespace Webong\Cogent\Attributes;

/**
 * Declare filterable fields on a resource, for JSON:API-style `filter[]` queries.
 *
 * Combine with `Post::interpret(PostResource::class)` to apply
 * spatie/laravel-query-builder filters automatically from your declarations.
 *
 *     #[Filterable(['title' => 'exact', 'body' => 'partial'])]
 *     final class PostResource extends JsonResource
 *
 *     $posts = Post::interpret(PostResource::class)->get();
 *
 * Filter types: `exact`, `partial`, `beginsWith`, `endsWith`, `belongsTo`, `scope`.
 * Omit the type to default to `exact`.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Filterable
{
    /**
     * @param  array<string, string>  $fields  Field name => filter type (exact, partial, beginsWith, endsWith, belongsTo, scope)
     */
    public function __construct(
        public array $fields = [],
    ) {
    }
}
