<?php

declare(strict_types=1);

namespace Webong\Cogent\Attributes;

use Attribute;

/**
 * Declare relations a resource renders only when the request asks for them
 * with ?include=, the same shape #[Includes] takes:
 *
 *     #[Includable('comments', ['author' => UserResource::class])]
 *
 * Anything the request names that is not declared here is ignored.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class Includable
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
        $this->relations = Includes::normalize($relations);
    }
}
