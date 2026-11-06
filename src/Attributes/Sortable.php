<?php

declare(strict_types=1);

namespace Webong\Cogent\Attributes;

/**
 * Declare sortable fields on a resource, for JSON:API-style `sort` queries.
 *
 * Combine with `Cogent::query()` to apply spatie/laravel-query-builder sorts
 * automatically based on your resource declarations.
 *
 *     #[Sortable(['title' => 'asc', 'created_at' => 'desc'])]
 *     final class PostResource extends JsonResource
 *
 * Direction: `asc` or `desc`. Omit to default to `asc`.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Sortable
{
    /**
     * @param  array<string, string>  $fields  Field name => direction (asc, desc)
     */
    public function __construct(
        public array $fields = [],
    ) {
    }
}
