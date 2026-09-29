<?php

declare(strict_types=1);

namespace Webong\Fluent\Tests\Fixtures\Collections;

use Illuminate\Database\Eloquent\Collection;
use Webong\Fluent\Concerns\CachesRelations;
use Webong\Fluent\Concerns\PlugsRelations;
use Webong\Fluent\Tests\Fixtures\Models\User;

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
