<?php

declare(strict_types=1);

namespace Webong\Cogent\Attributes;

/**
 * Cache a controller method's response using a named cache surface.
 *
 * The surface and domain determine the cache key, tags, and TTL (read from
 * the `cogent` config). Vary values from the request differentiate entries.
 * The entry is forgotten automatically when any tagged model is saved or
 * deleted through Eloquent.
 *
 * Put this on a controller method and add the `remember.response` middleware
 * to the route:
 *
 *     #[RememberResponse(surface: 'list', domain: 'posts', vary: ['locale', 'page'])]
 *     public function index(Request $request): Response
 *
 * Surfaces and their TTLs are configured in `config/cogent.php` under
 * `cogent.surfaces`. Set `enabled => false` on a surface to bypass caching.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class RememberResponse
{
    /**
     * @param  string  $surface  Named cache surface (list, counts, detail, scope, context).
     * @param  string  $domain   Cache domain grouping related resources (posts, users, comments, etc.).
     * @param  list<string>  $vary  Request input keys that differentiate the cache entry.
     * @param  bool  $shared  One entry for everyone instead of per authenticated user.
     */
    public function __construct(
        public string $surface = 'list',
        public string $domain = 'posts',
        public array $vary = [],
        public bool $shared = false,
    ) {}
}
