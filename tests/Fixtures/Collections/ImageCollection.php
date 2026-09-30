<?php

declare(strict_types=1);

namespace Webong\Cogent\Tests\Fixtures\Collections;

use Illuminate\Database\Eloquent\Collection;
use Webong\Cogent\Concerns\IncludesRelations;
use Webong\Cogent\Concerns\PlugsRelations;
use Webong\Cogent\Tests\Fixtures\Models\Image;

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
