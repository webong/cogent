<?php

declare(strict_types=1);

namespace Webong\Cogent\Attributes;

use Attribute;

/**
 * Cache what a resource renders for the given number of seconds. The entry is
 * forgotten when a model it rendered, or a row one of its relations or counts
 * read, is saved or deleted.
 *
 *     #[Remember(300)]
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Remember
{
    public function __construct(
        public readonly int $ttl,
    ) {
    }
}
