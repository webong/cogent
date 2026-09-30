<?php

declare(strict_types=1);

namespace Webong\Cogent\Tests\Fixtures\Collections;

use Illuminate\Database\Eloquent\Collection;
use Webong\Cogent\Concerns\CachesRelations;
use Webong\Cogent\Concerns\PlugsRelations;
use Webong\Cogent\Tests\Fixtures\Models\User;

/**
 * @template TModel of User
 *
 * @extends Collection<int, TModel>
 */
final class UserCollection extends Collection
{
    use CachesRelations;
    use PlugsRelations;
}
