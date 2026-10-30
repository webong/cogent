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

## Next

1. [ ] Dev guard: report lazy loads while a prepared resource renders (Output, Broadcast)
2. [ ] Dev guard: warn when a broadcast payload is over the driver limit (Broadcast)
3. [ ] Broadcast snapshots: queue the rendered payload instead of model identifiers (Event, Broadcast)
4. [ ] Batch model broadcasts in a request: prepare every model in one pass (Event, Broadcast)
5. [ ] Export resource plans: `php artisan cogent:plans --json` (Docs)
6. [ ] OpenAPI: `include` parameters, optional relations and counts from plans (Docs)
7. [ ] AsyncAPI: channels, event names and payloads from broadcast resources (Docs)
8. [ ] Run the suite against Laravel 11 (Compatibility)
9. [ ] Benchmark plain resources against prepared and cached ones, numbers in the README (Docs)
