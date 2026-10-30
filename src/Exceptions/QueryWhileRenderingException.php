<?php

declare(strict_types=1);

namespace Webong\Cogent\Exceptions;

use Illuminate\Database\Events\QueryExecuted;
use LogicException;

final class QueryWhileRenderingException extends LogicException
{
    /**
     * @param  class-string  $resource
     */
    public function __construct(
        public readonly string $resource,
        public readonly QueryExecuted $query,
    ) {
        parent::__construct(sprintf(
            '[%s] ran a query while rendering: %s. Load it with #[Includes], #[Includable] or #[Counts], or in prepare().',
            $resource,
            $query->sql,
        ));
    }
}
