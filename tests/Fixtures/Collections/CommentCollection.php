<?php

declare(strict_types=1);

namespace Webong\Fluent\Tests\Fixtures\Collections;

use Illuminate\Database\Eloquent\Collection;
use Webong\Fluent\Tests\Fixtures\Models\Comment;

/**
 * @template TModel of Comment
 *
 * @extends Collection<int, TModel>
 */
final class CommentCollection extends Collection
{
}
