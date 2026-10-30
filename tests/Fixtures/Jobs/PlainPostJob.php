<?php

declare(strict_types=1);

namespace Webong\Cogent\Tests\Fixtures\Jobs;

use Illuminate\Queue\SerializesModels;
use Webong\Cogent\Tests\Fixtures\Models\Post;

final class PlainPostJob
{
    use SerializesModels;

    public function __construct(public Post $post)
    {
    }
}
