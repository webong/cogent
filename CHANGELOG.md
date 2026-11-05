# Changelog

## Unreleased

### Added
- `Collection::macro('toResourceCollection')` — build a preparing resource collection from any Eloquent collection
- `config/cogent.php` — configurable cache surfaces with domains, registry, and per-surface enabled/TTL
- `CogentCacheNameBuilder` — CRM-style cache key/tag construction (`cogent:{domain}:{surface}:...`)
- `CogentCacheInvalidator` — tag-based cache invalidation (user, post, comment, surface, domain)
- `RememberResponse` attribute — declarative controller response caching with `surface`, `domain`, `vary`, `shared`, `when`
- `remember.response` middleware — reads `#[RememberResponse]` and caches responses via `SurfaceCache`
- `$request->remember()` macro — controller-level input/output caching with build closure
- `response()->remember()` macro — cache already-built response content
- Unified `cogent:plans --format` command (table, json, openapi, asyncapi)
- `docs/` directory — user-facing documentation (index.md, caching.md)

### Changed
- `cogent:asyncapi` command merged into `cogent:plans --format=asyncapi`
- `RememberResponse` attribute now uses `surface`/`domain` instead of `ttl`/`shared` as primary config
- `#[Remember]` on resources now works alongside controller-level caching macros

### Fixed
- `cogent:plans --format=openapi` and `--format=asyncapi` require `dedoc/scramble` (documented)

## [1.0.0] — Initial release

See `ROADMAP.md` for the full feature history.
