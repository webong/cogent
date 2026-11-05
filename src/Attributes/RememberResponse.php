<?php

declare(strict_types=1);

namespace Webong\Cogent\Attributes;

/**
 * Cache a controller method's response for the given number of seconds.
 *
 * The cache key is built from the route, any route-bound models, and the
 * `vary` values read from the request. The entry is forgotten automatically
 * when any cached model is saved or deleted through Eloquent.
 *
 * Put this on a controller method:
 *
 *     #[RememberResponse(ttl: 300, vary: ['locale'])]
 *     public function index(Request $request): Response
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class RememberResponse
{
    /**
     * @param  int  $ttl  Seconds to keep the cached response.
     * @param  bool  $shared  One entry for everyone instead of per authenticated user.
     * @param  list<string>  $vary  Request input keys that differentiate the cache entry.
     */
    public function __construct(
        public int $ttl = 60,
        public bool $shared = false,
        public array $vary = [],
    ) {}
}
