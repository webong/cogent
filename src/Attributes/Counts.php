<?php

declare(strict_types=1);

namespace Webong\Cogent\Attributes;

use Attribute;

/**
 * Declare the relation counts a resource renders. Each one is written to
 * {snake(relation)}_count, which is what {@see \Illuminate\Http\Resources\ConditionallyLoadsAttributes::whenCounted()} reads.
 *
 *     #[Counts('comments', 'likes')]
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class Counts
{
    /**
     * @var list<string>
     */
    public readonly array $relations;

    public function __construct(string ...$relations)
    {
        $this->relations = array_values($relations);
    }
}
