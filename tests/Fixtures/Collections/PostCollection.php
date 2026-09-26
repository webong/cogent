<?php

declare(strict_types=1);

namespace Webong\Fluent\Tests\Fixtures\Collections;

use Illuminate\Database\Eloquent\Collection;
use Webong\Fluent\Concerns\BatchesRelations;
use Webong\Fluent\Concerns\IncludesRelations;
use Webong\Fluent\Concerns\PlugsRelations;
use Webong\Fluent\Tests\Fixtures\Models\Post;

/**
 * @template TModel of Post
 *
 * @extends Collection<int, TModel>
 */
final class PostCollection extends Collection
{
    use BatchesRelations;
    use IncludesRelations;
    use PlugsRelations;
}
