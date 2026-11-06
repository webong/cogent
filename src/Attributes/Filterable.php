<?php

declare(strict_types=1);

namespace Webong\Cogent\Attributes;

/**
 * Declare filterable fields on a resource, for JSON:API-style `filter[]` queries.
 *
 * Combine with `Cogent::query()` to apply spatie/laravel-query-builder filters
 * automatically based on your resource declarations.
 *
 *     #[Filterable(['title' => 'exact', 'body' => 'partial'])]
 *     final class PostResource extends JsonResource
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
