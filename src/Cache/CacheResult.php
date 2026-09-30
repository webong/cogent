<?php

declare(strict_types=1);

namespace Webong\Cogent\Cache;

final readonly class CacheResult
{
    public function __construct(
        public mixed $value,
        public bool $hit,
    ) {
    }
}
