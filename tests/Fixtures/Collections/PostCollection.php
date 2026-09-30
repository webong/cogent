<?php

declare(strict_types=1);

namespace Webong\Cogent\Tests\Fixtures\Collections;

use Illuminate\Database\Eloquent\Collection;
use Webong\Cogent\Concerns\BatchesRelations;
use Webong\Cogent\Concerns\IncludesRelations;
use Webong\Cogent\Concerns\PlugsRelations;
use Webong\Cogent\Tests\Fixtures\Models\Post;

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
