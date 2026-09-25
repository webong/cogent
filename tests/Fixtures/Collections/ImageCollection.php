<?php

declare(strict_types=1);

namespace Webong\Fluent\Tests\Fixtures\Collections;

use Illuminate\Database\Eloquent\Collection;
use Webong\Fluent\Concerns\IncludesRelations;
use Webong\Fluent\Concerns\PlugsRelations;
use Webong\Fluent\Tests\Fixtures\Models\Image;

/**
 * @template TModel of Image
 *
 * @extends Collection<int, TModel>
 */
final class ImageCollection extends Collection
{
    use IncludesRelations;
    use PlugsRelations;
}
