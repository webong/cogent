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

## Next

1. [ ] Broadcast snapshots: queue the rendered payload instead of model identifiers (Event, Broadcast)
2. [ ] Batch model broadcasts in a request: prepare every model in one pass (Event, Broadcast)
3. [ ] Export resource plans: `php artisan cogent:plans --json` (Docs)
4. [ ] OpenAPI: `include` parameters, optional relations and counts from plans (Docs)
5. [ ] AsyncAPI: channels, event names and payloads from broadcast resources (Docs)
6. [ ] Run the suite against Laravel 11 (Compatibility)
7. [ ] Benchmark plain resources against prepared and cached ones, numbers in the README (Docs)
