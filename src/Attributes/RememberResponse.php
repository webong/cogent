<?php

declare(strict_types=1);

namespace Webong\Cogent\Attributes;

use Closure;

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
 *
 * Vary values can be request input keys (strings) or closures that receive
 * the request and return a value:
 *
 *     #[RememberResponse(
 *         surface: 'list',
 *         domain: 'posts',
 *         vary: ['locale', fn (Request $request) => $request->header('x-app-version')]
 *     )]
 *
 * Only cache when a condition is met:
 *
 *     #[RememberResponse(
 *         surface: 'detail',
 *         domain: 'posts',
 *         when: fn (Request $request) => $request->query('cache') === '1'
 *     )]
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class RememberResponse
{
    /**
     * @param  string  $surface  Named cache surface (list, counts, detail, scope, context).
     * @param  string  $domain   Cache domain grouping related resources (posts, users, comments, etc.).
     * @param  list<string|Closure(Request): mixed>  $vary  Request input keys or closures that differentiate the cache entry.
     * @param  bool  $shared  One entry for everyone instead of per authenticated user.
     * @param  Closure(Request): bool|null  $when  Only cache when this returns true. Null = always cache.
     */
    public function __construct(
        public string $surface = 'list',
        public string $domain = 'posts',
        public array $vary = [],
        public bool $shared = false,
        public ?Closure $when = null,
    ) {}
}
