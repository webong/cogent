# Roadmap

Cogent across the whole path of a Laravel object: Request, Event, Output and Broadcast.

## Done

- [x] Prepare resources from `#[Includes]`, `#[Counts]` and `prepare()` (Output)
- [x] Load requested relations with `#[Includable]` (Request)
- [x] Broadcast event models through their resources (Broadcast)
- [x] Broadcast model events as the model resource (Broadcast)
- [x] Restore queued models through resource plans (Event)
- [x] Cache rendered resources with `#[Remember]` (Output)
- [x] Vary cached output by the authenticated user unless it is marked shared (Output)
- [x] Dev guard: report queries while a prepared resource renders (Output, Broadcast)
- [x] Dev guard: check broadcast payloads against the driver limit (Broadcast)
- [x] Broadcast snapshots: render the payload when the event is queued (Event, Broadcast)
- [x] Batch model broadcasts: prepare every model in one pass with `Cogent::batchBroadcasts()` (Event, Broadcast)
- [x] Export resource plans: `php artisan cogent:plans --json` (Docs)
- [x] OpenAPI components: include parameters, plans and Scramble-inferred response schemas (Docs)
- [x] AsyncAPI: channels, event names and resource payloads with `cogent:asyncapi` (Docs)
- [x] Benchmark plain resources against prepared and cached ones, numbers in the README (Docs)
- [x] Require Laravel 12: Laravel 11 has open security advisories that block normal Composer installs (Compatibility)
- [x] Controller-level response caching: `#[RememberResponse]` attribute, `$request->remember()`, `response()->remember()` (Output)
- [x] Config-driven cache surfaces with domains, registry, and per-surface enabled/TTL (Output)
- [x] Unified `cogent:plans --format` command replacing standalone `cogent:asyncapi` (Docs)
- [x] User-facing documentation in `docs/` directory (Docs)
