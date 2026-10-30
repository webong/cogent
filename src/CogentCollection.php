<?php

declare(strict_types=1);

namespace Webong\Cogent;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Webong\Cogent\Concerns\BatchesRelations;
use Webong\Cogent\Concerns\CachesRelations;
use Webong\Cogent\Concerns\IncludesRelations;
use Webong\Cogent\Concerns\PlugsRelations;

/**
 * An Eloquent collection with every Cogent concern, for models whose own
 * collection does not use them. Extend it, or wrap models in it.
 *
 * @template TModel of Model
 *
 * @extends Collection<int, TModel>
 *
 * @phpstan-consistent-constructor
 */
class CogentCollection extends Collection
{
    use BatchesRelations;
    use CachesRelations;
    use IncludesRelations;
    use PlugsRelations;
}
