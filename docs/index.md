# Documentation

Cogent can generate machine-readable API documentation from your code. Two formats are supported:

| Format | Command | Use case |
|---|---|---|
| **OpenAPI 3.1** | `cogent:plans --format=openapi` | REST API docs: include parameters, resource schemas, plans |
| **AsyncAPI 3.0** | `cogent:plans --format=asyncapi` | WebSocket broadcast docs: channels, events, payloads |

Both require `dedoc/scramble` for schema inference:

```bash
composer require --dev dedoc/scramble
```

---

## OpenAPI 3.1

Generate OpenAPI components for every preparing resource in `app/Http/Resources`:

```bash
php artisan cogent:plans --format=openapi
```

### Output

The command prints an OpenAPI 3.1 document with:

- **`components.schemas`** — one schema per resource, inferred from `toArray()` via Scramble
- **`components.parameters`** — one `include` query parameter per resource, with `enum` values limited to the allowed `?include=` paths
- **`x-cogent.resources`** — each plan as a Cogent extension (includes, counts, remember, requestable paths)

### Example

```json
{
  "openapi": "3.1.0",
  "components": {
    "parameters": {
      "PostResourceInclude": {
        "name": "include",
        "in": "query",
        "schema": {
          "type": "array",
          "items": { "type": "string", "enum": ["author", "comments"] },
          "uniqueItems": true
        }
      }
    },
    "schemas": {
      "PostResource": {
        "type": "object",
        "properties": {
          "id": { "type": "integer" },
          "title": { "type": "string" },
          "author": { "$ref": "#/components/schemas/UserResource" }
        }
      }
    }
  },
  "x-cogent": {
    "resources": {
      "PostResource": {
        "includes": { "author": "App\\Http\\Resources\\UserResource" },
        "counts": ["comments"],
        "remember": { "ttl": 300, "shared": false },
        "requestable": ["author", "comments"]
      }
    }
  }
}
```

### Reference in your OpenAPI spec

```yaml
components:
  parameters:
    PostResourceInclude:
      $ref: './cogent-openapi.json#/components/parameters/PostResourceInclude'
  schemas:
    PostResource:
      $ref: './cogent-openapi.json#/components/schemas/PostResource'
```

Save the output to a file:

```bash
php artisan cogent:plans --format=openapi > public/docs/cogent-openapi.json
```

---

## AsyncAPI 3.0

Generate AsyncAPI documents for broadcast events and model events:

```bash
php artisan cogent:plans --format=asyncapi \
    --events-path=app/Events \
    --models-path=app/Models \
    --title="My Application WebSockets"
```

### What it does (and doesn't do)

AsyncAPI is **documentation only** — it describes what your broadcast events look like. It does **not** handle WebSocket connections at runtime (that's Laravel Reverb/Pusher's job).

It scans your event and model classes and produces an AsyncAPI 3.0 document so frontend teams know:

- What channels events are broadcast on (public, private, presence)
- What event names to listen for
- What payload shape each event has
- Which parts of channel addresses are dynamic (e.g., `posts.{id}`)

### What it discovers

**Events** — classes using `BroadcastsResources` or `SnapshotsBroadcasts`:
- Event name (from `broadcastAs()` or class name)
- Broadcast channels (parsed from `broadcastOn()`, with dynamic parameters marked)
- Payload schema (from the resource that renders the event's models)

**Models** — classes using `BroadcastsAsResource`:
- `created`, `updated`, `deleted` events for each model
- Channel and payload for each event

### Output

An AsyncAPI 3.0 document with `channels`, `operations`, `messages`, and `schemas`.

Save the output:

```bash
php artisan cogent:plans --format=asyncapi > public/docs/cogent-asyncapi.json
```

### Example

```json
{
  "asyncapi": "3.0.0",
  "channels": {
    "PostUpdatedChannel": {
      "address": "private-posts.{parameter}",
      "x-cogent": { "type": "private", "dynamic": true }
    }
  },
  "operations": {
    "PostUpdated": {
      "action": "send",
      "channel": { "$ref": "#/channels/PostUpdatedChannel" }
    }
  },
  "components": {
    "messages": {
      "PostUpdated": {
        "name": "post.updated",
        "payload": { "$ref": "#/components/schemas/PostUpdatedPayload" }
      }
    },
    "schemas": {
      "PostResource": { "type": "object", ... }
    }
  }
}
```

---

### Runtime vs Documentation: the full picture

| Layer | Runtime (handles live requests/events) | Documentation (spec only) |
|---|---|---|
| **Eloquent / Fluent** | `plug`, `include`, `batchCount` on collections | — |
| **Request** | spatie/laravel-query-builder via `Cogent::query()` | OpenAPI 3.1 |
| **Output (Resource)** | `PreparesResources`, `#[Remember]`, output cache | — |
| **Event / Broadcast** | Laravel Reverb/Pusher via `BroadcastsResources` | AsyncAPI 3.0 |

**Neither OpenAPI nor AsyncAPI processes live traffic.** They're machine-readable descriptions generated from your resource attributes. The runtime work is done by spatie (query params) and Laravel's broadcasting system (WebSocket events), both driven by the same attribute declarations on your resources.

---

## JSON plans

Dump raw plan data for every preparing resource:

```bash
php artisan cogent:plans --format=json --path=app/Http/Resources/Api
```

Useful for custom tooling or CI checks.

---

## Table view (default)

A human-readable table of all preparing resources:

```bash
php artisan cogent:plans
```

---

## Installing Scramble

OpenAPI and AsyncAPI generation require `dedoc/scramble`:

```bash
composer require --dev dedoc/scramble
```

Without it, `--format=openapi` and `--format=asyncapi` will fail with:

```
Install dedoc/scramble to infer resource schemas: composer require --dev dedoc/scramble
```

---

## Caching

See [caching.md](./caching.md) for the full controller-level caching guide.
