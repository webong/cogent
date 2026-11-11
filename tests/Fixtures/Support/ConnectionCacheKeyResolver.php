<?php

declare(strict_types=1);

namespace Webong\Cogent\Tests\Fixtures\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * A dynamic cache key resolver that reads runtime state off the model.
 *
 * Mirrors the use case in issue #6: the key depends on the connection the
 * model uses, which is only known at cache time.
 */
final class ConnectionCacheKeyResolver
{
    public function __invoke(Model $model, mixed $id): string
    {
        return 'cogent-test:dynamic:'.$model->getConnection()->getName().':'.$id;
    }
}
