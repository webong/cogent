<?php

declare(strict_types=1);

namespace Webong\Cogent\Exceptions;

use LogicException;

final class BroadcastTooLargeException extends LogicException
{
    public function __construct(
        public readonly object $event,
        public readonly int $bytes,
        public readonly int $limit,
    ) {
        parent::__construct(sprintf(
            'The [%s] broadcast payload is %d bytes, over the limit of %d. Render less in its resources, or broadcast an identifier for clients to fetch.',
            $event::class,
            $bytes,
            $limit,
        ));
    }
}
