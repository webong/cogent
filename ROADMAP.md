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
- [x] OpenAPI components: `include` parameters and plans with `cogent:plans --openapi` (Docs)
- [x] Run the suite against Laravel 11.57: fixed `ModelIdentifier`, guard blame and naming convention (Compatibility)

## Next

1. [ ] Response and payload schemas: needs a decision, Scramble's analysis of `toArray()` or schemas declared on resources (Docs)
2. [ ] AsyncAPI: channels, event names and payloads from broadcast resources (Docs)
3. [ ] Decide on Laravel 11: every release has open security advisories, so CI can only test it with the advisory block off (Compatibility)
4. [ ] Benchmark plain resources against prepared and cached ones, numbers in the README (Docs)
