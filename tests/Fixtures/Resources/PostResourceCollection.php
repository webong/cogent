<?php

declare(strict_types=1);

namespace Webong\Cogent\Tests\Fixtures\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;
use Webong\Cogent\Concerns\PreparesResources;

final class PostResourceCollection extends ResourceCollection
{
    use PreparesResources;

    /**
     * @var class-string
     */
    public $collects = PostResource::class;
}
