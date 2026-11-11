<?php

declare(strict_types=1);

namespace Webong\Cogent\Tests\Fixtures\Collections;

use Illuminate\Database\Eloquent\Collection;
use Webong\Cogent\Concerns\CachesRelations;
use Webong\Cogent\Concerns\PlugsRelations;
use Webong\Cogent\Tests\Fixtures\Models\DynamicKeyPost;

/**
 * @template TModel of DynamicKeyPost
 *
 * @extends Collection<int, TModel>
 */
final class DynamicKeyPostCollection extends Collection
{
    use CachesRelations;
    use PlugsRelations;
}
