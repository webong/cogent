<?php

declare(strict_types=1);

namespace Webong\Cogent\Attributes;

use Attribute;

/**
 * Declare the relations a resource renders, so they are loaded for the whole
 * collection before any item is transformed.
 *
 * Takes relation names, or an array that maps a relation to the resource that
 * renders it, the same shape {@see \Illuminate\Database\Eloquent\Builder::with()} takes:
 *
 *     #[Includes('author', 'comments')]
 *     #[Includes(['author' => UserResource::class, 'comments'])]
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class Includes
{
    /**
     * @var array<int|string, string|null>
     */
    public readonly array $relations;

    /**
     * @param  string|array<int|string, string>  ...$relations
     */
    public function __construct(string|array ...$relations)
    {
        $this->relations = self::normalize($relations);
    }

    /**
     * @param  array<int, string|array<int|string, string>>  $relations
     * @return array<int|string, string|null>
     */
    public static function normalize(array $relations): array
    {
        $normalized = [];

        foreach ($relations as $relation) {
            foreach ((array) $relation as $name => $resource) {
                if (is_int($name)) {
                    $normalized[$resource] ??= null;

                    continue;
                }

                $normalized[$name] = $resource;
            }
        }

        return $normalized;
    }
}
