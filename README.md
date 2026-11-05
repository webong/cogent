# Cogent

Cogent helps your Laravel app render models fast, without writing `with()` and `withCount()` everywhere.

You declare what a resource needs once — right on the resource — and Cogent loads it for every model, in one batched pass, just before it renders.

```php
#[Includes(['author' => UserResource::class])]
#[Counts('comments')]
final class PostResource extends JsonResource
{
    use PreparesResources;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'author' => new UserResource($this->whenLoaded('author')),
            'comments_count' => $this->whenCounted('comments'),
        ];
    }
}

// One query for posts, one for authors, one for comment counts — no matter how many posts.
return PostResource::collection(Post::query()->paginate());
```

Cogent works across the whole path of a Laravel object:

| Area | What Cogent does |
|---|---|
| **Eloquent** | Plug, include and count relations on collections already in memory — no extra queries. |
| **Request** | Load only the relations a request asks for (`?include=`), never more. |
| **Output (Resource)** | Prepare every resource's relations and counts before rendering; cache the rendered output. |
| **Event / Broadcast** | Send models over WebSockets as their resources; restore queued models without re-querying. |

## Installation

```bash
composer require webong/cogent
```

The service provider is auto-discovered, so nothing else is required.

---

## Eloquent — relations on collections already in memory

Cogent starts with collections you already have. Instead of letting Eloquent re-query relations, you attach, include, or count what's already loaded.

### Plug relations you already resolved

```php
$conversations->plug('channel', $channels->get($conversation->channel_id));

// Or resolve per model:
$conversations->plug('channel', fn (Conversation $c) =>
    $channels->get($c->channel_id));

// Only attach to models that don't have it loaded yet:
$conversations->plugMissing('channel', $channels);
```

### Include related models already loaded

`include()` walks relations that are in memory and returns every related model, deduplicated so the same row is never held twice:

```php
$graph = $conversations->include('channel', 'participants'); // no queries

// Fetch anything not loaded yet, batched by model class:
$conversations->includeMissing('channel.contacts');
```

### Count relations in one query

`batchCount()` writes `{relation}_count` on every model with one grouped query:

```php
$conversations->batchCount('messages');
// now $conversation->messages_count is available
```

---

## Request — load only what the client asks for

`#[Includable]` declares relations that load only when the request names them:

```php
#[Includable(['author' => UserResource::class], 'comments')]
final class PostResource extends JsonResource
{
    use PreparesResources;
}
```

A client can then request `?include=author,comments.post`. Anything not declared is ignored, so a client can never load more than the resource allows.

Need a different query parameter than `?include=`? Register it once in a service provider:

```php
Cogent::includeParameter('with');
```

---

## Output (Resource) — prepare, cache, guard

This is where Cogent pays off. Add `PreparesResources` to a resource and declare what it renders:

```php
#[Includes(['author' => UserResource::class, 'channel'])]
#[Counts('comments')]
final class PostResource extends JsonResource
{
    use PreparesResources;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'author' => new UserResource($this->whenLoaded('author')),
            'channel' => $this->whenLoaded('channel'),
            'comments_count' => $this->whenCounted('comments'),
        ];
    }
}
```

- `#[Includes]` loads the listed relations for every model, once, sharing duplicate instances.
- `#[Counts]` writes `{relation}_count` on every model with one grouped query.
- Relations already loaded are left alone.

Use it anywhere:

```php
return PostResource::collection(Post::query()->paginate());
return Post::query()->get()->toResourceCollection(PostResource::class);
return new PostResource($post);
```

A single resource prepares its one model. Collections and paginators prepare every model in one batched pass.

### Custom preparation with `prepare()`

For anything the attributes can't express, add a static `prepare()` hook. It runs once per resolve with every model:

```php
public static function prepare(CogentCollection $posts, Request $request): void
{
    $posts->plugMissing('channel', $request->user()->channels);
}
```

### Cache rendered output (`#[Remember]`)

Cache what a resource renders so a cache hit skips preparing and `toArray()` entirely:

```php
#[Remember(300)]
#[Includes(['author' => UserResource::class])]
#[Counts('comments')]
final class PostResource extends JsonResource
{
    use PreparesResources;
}
```

```php
return PostResource::collection($posts);   // cached for 300 seconds
return PostResource::collection($posts)->remember(60); // or per call
```

Each authenticated user gets their own entry unless you mark it shared:

```php
#[Remember(300, shared: true)]
```

### Controller-level caching (Input / Output)

`#[Remember]` lives on the resource. If you want to cache an entire controller response — including anything the controller adds beyond the resource — use the `remember()` macro on the request, or inject `OutputCache` directly. This is an Input/Output cache: the input is the models plus anything the output varies on, and the output is the rendered array. It auto-forgets when any of those models (or rows reached through their loaded relations) is saved or deleted.

Using the request macro (simplest):

```php
public function index(Request $request)
{
    $posts = Post::query()->orderBy('created_at', 'desc')->get();

    return $request->remember(
        resource: PostResource::class,
        models: $posts->all(),
        vary: ['locale' => app()->getLocale(), 'page' => $request->input('page')],
        ttl: 300,
        build: fn () => PostResource::collection($posts)->resolve($request),
    );
}
```

Or inject `OutputCache` directly:

```php
use Webong\Cogent\Resources\OutputCache;

public function index(OutputCache $cache, Request $request)
{
    $posts = Post::query()->orderBy('created_at', 'desc')->get();

    return $cache->remember(
        resource: PostResource::class,
        models: $posts->all(),
        vary: ['locale' => app()->getLocale(), 'page' => $request->input('page')],
        ttl: 300,
        build: fn () => PostResource::collection($posts)->resolve($request),
    );
}
```

- `models` — the models being rendered, in order. The cache key is built from them.
- `vary` — anything else the output depends on (locale, viewer, filters). Each combination gets its own entry.
- `ttl` — seconds to keep the entry.
- `build` — returns the output array. Only runs on a cache miss.

Invalidation is automatic: saving or deleting a rendered model, or any row reached through a loaded relation or `#[Counts]`, forgets the entry.

### Make sure nothing lazy-loads while rendering

Turn on the dev guard so any query that sneaks in while a resource renders throws instead of silently becoming an N+1:

```php
// In a service provider's boot():
Cogent::guardResources(! app()->isProduction());

Cogent::handleResourceQueriesUsing(function (string $resource, \Illuminate\Database\Events\QueryExecuted $query): void {
    logger()->warning("{$resource} queried while rendering", ['sql' => $query->sql]);
});
```

---

## Event / Broadcast — WebSockets and queues

Send models over WebSockets as their resources, so clients get the same shape over a socket as over HTTP:

```php
use Webong\Cogent\Attributes\WithResource;
use Webong\Cogent\Concerns\BroadcastsResources;

final class PostUpdated implements ShouldBroadcast
{
    use BroadcastsResources;

    public function __construct(
        public Post $post,
        #[WithResource(EditorResource::class)] public User $editor,
    ) {}
}
```

Models without a resource fall back to `toArray()`.

For models that broadcast their own events, use `BroadcastsAsResource` instead of Laravel's `BroadcastsEvents` — every model event is sent as the model's resource:

```php
#[UseResource(PostResource::class)]
class Post extends Model
{
    use BroadcastsAsResource;
}
```

Batch many model broadcasts so every model is prepared in one pass and nothing is sent if the callback throws:

```php
Cogent::batchBroadcasts(function () use ($posts): void {
    $posts->each->update(['published' => true]);
});
```

Check payloads against the driver limit (Pusher 10KB, Reverb ~10,000 bytes):

```php
Cogent::limitBroadcasts(10_000);

Cogent::handleOversizedBroadcastsUsing(function (object $event, int $bytes, int $limit): void {
    logger()->warning($event::class." broadcast is {$bytes} bytes, over {$limit}");
});
```

### Queued events and jobs

Laravel's `SerializesModels` re-queries every restored model, losing counts and cached relations. Use `SerializesPreparedModels` instead — restored models load what their resource renders, and cacheable relations come from the cache:

```php
use Webong\Cogent\Concerns\SerializesPreparedModels;

final class PostUpdated implements ShouldBroadcast
{
    use BroadcastsResources;
    use SerializesPreparedModels;

    public function __construct(
        public Post $post,
        #[WithResource(EditorResource::class)] public User $editor,
    ) {}
}
```

---

## Artisan commands

```bash
# Human-readable table of what every preparing resource loads, counts and caches
php artisan cogent:plans

# Same as JSON, for tooling
php artisan cogent:plans --format=json

# OpenAPI 3.1 components: include parameters, plans and Scramble-inferred response schemas
php artisan cogent:plans --format=openapi

# AsyncAPI 3.0 document: broadcast channels, event names and resource payloads
php artisan cogent:plans --format=asyncapi \
    --events-path=app/Events --models-path=app/Models --title="My broadcasts"
```

Scan custom directories:

```bash
php artisan cogent:plans --path=app/Http/Resources/Api --format=json
php artisan cogent:plans --format=asyncapi --events-path=app/Events --models-path=app/Models
```

The OpenAPI and AsyncAPI formats require `composer require --dev dedoc/scramble`.

## Running the tests

```bash
composer install
composer test
```

## License

MIT.
