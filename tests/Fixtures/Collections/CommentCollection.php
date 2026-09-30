<?php

declare(strict_types=1);

namespace Webong\Cogent\Tests\Fixtures\Collections;

use Illuminate\Database\Eloquent\Collection;
use Webong\Cogent\Tests\Fixtures\Models\Comment;

/**
 * @template TModel of Comment
 *
 * @extends Collection<int, TModel>
 */
final class CommentCollection extends Collection
{
}
