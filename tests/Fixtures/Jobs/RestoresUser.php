<?php

declare(strict_types=1);

namespace Webong\Cogent\Tests\Fixtures\Jobs;

use Webong\Cogent\Concerns\SerializesPreparedModels;
use Webong\Cogent\Tests\Fixtures\Models\User;

final class RestoresUser
{
    use SerializesPreparedModels;

    public function __construct(public User $user)
    {
    }
}
