<?php

declare(strict_types=1);

namespace Webong\Cogent\Resources;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Webong\Cogent\Concerns\PreparesResources;

/**
 * The collection a preparing resource builds from collection() and toResourceCollection().
 */
class PreparedResourceCollection extends AnonymousResourceCollection
{
    use PreparesResources;
}
