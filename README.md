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
| **Eloquent / Fluent** | Plug, include and count relations on collections already in memory — no extra queries. |
| **Request (Input/Output)** | Load only the relations a request asks for (`?include=`); prepare resource relations and counts before rendering; cache rendered output at the resource or controller level. |
| **Event / Broadcast** | Send models over WebSockets as their resources; restore queued models without re-querying; batch broadcasts and check payload size. |

## Installation

```bash
composer require webong/cogent
```

The service provider is auto-discovered. Requires PHP 8.3+ and Laravel 12 or 13.

---

## Eloquent / Fluent — relations on collections already in memory

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

## Request (Input/Output) — prepare, cache, guard

### Requested relations (`?include=`)

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

### The Cogent approach: unified query interface

Cogent provides one interface for JSON:API-style query params (`filter[]`, `sort`, `include`), OpenAPI docs, and AsyncAPI docs — all driven by resource attribute declarations. You don't use spatie/laravel-query-builder directly; Cogent wraps it.

Declare what your resource accepts right on the resource class:

```php
use Webong\Cogent\Attributes\Filterable;
use Webong\Cogent\Attributes\Includable;
use Webong\Cogent\Attributes\Sortable;
use Webong\Cogent\Concerns\PreparesResources;

#[Filterable(['title' => 'exact', 'body' => 'partial'])]
#[Sortable(['title' => 'asc', 'created_at' => 'desc'])]
#[Includable(['author' => UserResource::class], 'comments')]
final class PostResource extends JsonResource
{
    use PreparesResources;
}
```

Then query through `Cogent::query()` instead of spatie's `QueryBuilder::for()`:

```php
use Webong\Cogent\Cogent;

// GET /posts?filter[title]=Hello&sort=-created_at&include=author
$posts = Cogent::query(Post::query(), PostResource::class)->get();

// Or paginated:
$posts = Cogent::query(Post::query(), PostResource::class)->paginate(15);

// Or cursor-paginated:
$posts = Cogent::query(Post::query(), PostResource::class)->cursorPaginate(15);

// Access the underlying spatie QueryBuilder for advanced customization:
$spatie = Cogent::query(Post::query(), PostResource::class)->spatie();
```

`Cogent::query()` reads `#[Filterable]`, `#[Sortable]`, and `#[Includable]` from the resource class and applies them automatically through spatie. When the resource resolves, `PreparesResources` loads anything still missing and applies `#[Counts]` — spatie eager-loads the includes, Cogent fills in the gaps.

**Attribute options:**

| Attribute | What it declares | Query param |
|---|---|---|
| `#[Filterable(['title' => 'exact'])]` | Filterable fields and types | `filter[title]` |
| `#[Sortable(['title' => 'asc'])]` | Sortable fields and directions | `sort` |
| `#[Includable(['author'], 'comments')]` | Includable relations | `?include=` |

Filter types: `exact`, `partial`, `beginsWith`, `endsWith`, `belongsTo`, `scope`. Default: `exact`.
Sort directions: `asc`, `desc`. Default: `asc`.

#### Read metadata for documentation

```php
use Webong\Cogent\QueryBuilder\CogentQueryBuilder;

$metadata = CogentQueryBuilder::metadataFor(PostResource::class);

// ['author' => null, 'comments' => null]
$metadata['includes'];

// ['title' => 'exact', 'body' => 'partial']
$metadata['filters'];

// ['title' => 'asc', 'created_at' => 'desc']
$metadata['sorts'];
```

Pass it to `PlanExporter::openApi()` so OpenAPI output includes `filter[]` and `sort` parameters alongside `?include=`:

```php
use Webong\Cogent\Resources\PlanExporter;

$exporter = new PlanExporter();
$document = $exporter->openApi(
    [PostResource::class],
    'include',
    [PostResource::class => CogentQueryBuilder::metadataFor(PostResource::class)],
);
```

The resulting OpenAPI document gets `filter[title]`, `filter[body]`, and `sort` query parameters in `components.parameters`, plus an `x-cogent` extension carrying the filter type and sort direction for each.

Combined with `#[Includable]` on your resources, this gives you full JSON:API-style query support: filtering, sorting, and includes — all declared, all documented, all validated.

### Declaring what a resource renders

Add `PreparesResources` to a resource and declare what it renders:

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

`#[Remember]` lives on the resource. If you want to cache an entire controller response — including anything the controller adds beyond the resource — use the `remember()` macro on the request or response, or inject `OutputCache` directly. This is an Input/Output cache: the input is the models plus anything the output varies on, and the output is the rendered array. It auto-forgets when any of those models (or rows reached through their loaded relations) is saved or deleted.

#### Configuration (`config/cogent.php`)

Cogent ships a config file with named cache surfaces, each with its own `enabled` flag and `ttl`:

```php
return [
    'domains' => ['posts', 'users', 'comments', 'channels', 'conversations'],
    'registry' => [
        'posts' => ['list', 'counts', 'detail', 'scope'],
        'users' => ['list', 'detail', 'scope'],
        'comments' => ['list', 'counts'],
        'channels' => ['scope'],
        'conversations' => ['list', 'counts', 'context', 'scope', 'detail'],
    ],
    'surfaces' => [
        'list' => ['enabled' => false, 'ttl' => 30],
        'counts' => ['enabled' => false, 'ttl' => 20],
        'context' => ['enabled' => false, 'ttl' => 60],
        'scope' => ['enabled' => false, 'ttl' => 120],
        'detail' => ['enabled' => false, 'ttl' => 30],
    ],
];
```

Enable surfaces via environment variables:

```
COGENT_LIST_CACHE_ENABLED=true
COGENT_LIST_CACHE_TTL=30
```

#### Using the request macro (wraps the build closure)

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

#### Using the response macro (wraps an already-built response)

```php
public function show(Post $post)
{
    return response()->json(new PostResource($post))->remember(
        resource: PostResource::class,
        models: [$post],
        ttl: 300,
    );
}
```

#### Injecting `OutputCache` directly

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

#### Attribute-based caching on controller methods

Configure caching declaratively with `#[RememberResponse]` on a controller method. Register the `remember.response` middleware on the route:

```php
use Webong\Cogent\Attributes\RememberResponse;

// In routes/web.php or routes/api.php:
Route::get('/posts', [PostController::class, 'index'])
    ->middleware('remember.response');
```

Then on the controller method:

```php
use Webong\Cogent\Attributes\RememberResponse;

#[RememberResponse(surface: 'list', domain: 'posts', vary: ['locale', 'page'])]
public function index(Request $request)
{
    $posts = Post::query()->orderBy('created_at', 'desc')->get();

    return PostResource::collection($posts)->resolve($request);
}
```

**Options:**

| Option | Description |
|---|---|
| `surface` | Named cache surface (`list`, `counts`, `detail`, `scope`, `context`). Must be enabled in `config/cogent.php`. |
| `domain` | Cache domain grouping related resources (`posts`, `users`, `comments`, etc.). |
| `vary` | Request input keys or closures that differentiate the cache entry. |
| `shared` | One entry for everyone instead of per authenticated user. |
| `when` | Closure receiving the request; only cache when it returns `true`. |

`vary` accepts request input keys (strings) or closures that receive the request and return a value:

```php
#[RememberResponse(
    surface: 'list',
    domain: 'posts',
    vary: [
        'locale',
        'page',
        fn (Request $request) => $request->header('x-app-version'),
    ],
)]
```

Only cache when a condition is met:

```php
#[RememberResponse(
    surface: 'detail',
    domain: 'posts',
    when: fn (Request $request) => $request->query('cache') === '1',
)]
```

Domains and surfaces used in `#[RememberResponse]` attributes are automatically registered into `config/cogent.php` at runtime.

#### Make sure nothing lazy-loads while rendering

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

### Broadcast models through their resources

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

### Broadcast documentation (AsyncAPI)

AsyncAPI is **documentation only** — it describes what your broadcast events look like; it doesn't handle WebSocket connections at runtime (that's Reverb/Pusher's job).

Generate an AsyncAPI 3.0 document so frontend teams know what channels to subscribe to and what payload shapes to expect:

```bash
php artisan cogent:plans --format=asyncapi \
    --events-path=app/Events \
    --models-path=app/Models \
    --title="My Application WebSockets"
```

It scans events using `BroadcastsResources`/`SnapshotsBroadcasts` and models using `BroadcastsAsResource`, producing:

- **Channels** — where events broadcast (public, private, presence), with dynamic addresses marked
- **Operations** — send actions for each event
- **Messages** — event names and payload schemas
- **Schemas** — the resource shapes broadcast over the socket

The OpenAPI and AsyncAPI formats require `composer require --dev dedoc/scramble`.

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
