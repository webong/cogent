# Controller-Level Caching

Cogent provides four ways to cache entire controller responses. All share the same Input/Output model:

- **Input**: the models being rendered + anything the output varies on
- **Output**: the rendered response array/content
- **Invalidation**: automatic when any rendered model (or row reached through loaded relations / `#[Counts]`) is saved or deleted

---

## Configuration (`config/cogent.php`)

Cogent ships a config file with named cache surfaces, each with `enabled` and `ttl`:

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
COGENT_DETAIL_CACHE_ENABLED=true
COGENT_DETAIL_CACHE_TTL=30
```

Domains and surfaces used in `#[RememberResponse]` attributes are auto-registered at runtime — you don't need to manually add them to the registry.

---

## Option 1: Request macro (`$request->remember()`)

Wrap the resource resolution in a closure. Only runs on a cache miss.

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

- `models` — the models being rendered, in order. Used to build the cache key.
- `vary` — anything else the output depends on (locale, viewer, filters). Each combination gets its own entry.
- `ttl` — seconds to keep the entry.
- `build` — returns the output array. Only runs on a cache miss.

---

## Option 2: Response macro (`response()->remember()`)

Wrap an already-built response. On a cache hit, returns a new response with cached content; on a miss, stores the current content.

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

No `build` closure — the response is already built. The middleware caches `$response->getContent()`.

---

## Option 3: Inject `OutputCache` directly

Full control over the caching logic:

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

---

## Option 4: `#[RememberResponse]` attribute (declarative)

Register the `remember.response` middleware on the route:

```php
// routes/api.php
Route::get('/posts', [PostController::class, 'index'])
    ->middleware('remember.response');
```

Then on the controller method:

```php
use Webong\Cogent\Attributes\RememberResponse;
use Illuminate\Http\Request;

#[RememberResponse(surface: 'list', domain: 'posts', vary: ['locale', 'page'])]
public function index(Request $request)
{
    $posts = Post::query()->orderBy('created_at', 'desc')->get();

    return PostResource::collection($posts)->resolve($request);
}
```

### Attribute options

| Option | Type | Description |
|---|---|---|
| `surface` | `string` | Named cache surface (`list`, `counts`, `detail`, `scope`, `context`) |
| `domain` | `string` | Cache domain grouping related resources (`posts`, `users`, `comments`) |
| `vary` | `array` | Request input keys or closures that differentiate the cache entry |
| `shared` | `bool` | One entry for everyone instead of per authenticated user |
| `when` | `?Closure(Request): bool` | Only cache when this returns `true`. Null = always cache |

### Vary values — strings or closures

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

String values are read from `$request->input($key)`. Closures receive the request and return a value that's included in the cache key hash.

### Conditional caching (`when`)

```php
#[RememberResponse(
    surface: 'detail',
    domain: 'posts',
    when: fn (Request $request) => $request->query('cache') === '1',
)]
```

Return `false` to skip caching entirely for that request — the controller still runs normally.

### Auto-registration

The first time a route with a `#[RememberResponse]` attribute is hit, the middleware ensures the domain/surface combination exists in `config/cogent.registry`. You don't need to manually add new domains/surfaces to config.

### Cache key construction

The key is built from:
- Domain + surface
- Route-bound models (class:key pairs)
- `vary` values (input keys or closure return values)
- Authenticated user (unless `shared: true`)

Example: `cogent:posts:list:sha1({"vary":{"locale":"en","page":1,"user":42},"models":{"App\\Models\\Post":1}})`

---

## Invalidation

All four approaches share the same invalidation behavior:

- Saving or deleting a rendered model forgets all cache entries tagged with that model
- Saving or deleting a row reached through a loaded relation or `#[Counts]` also forgets the entry
- The `eloquent.saved` and `eloquent.deleted` events trigger invalidation globally

Example:

```php
$post->update(['title' => 'New title']);  // forgets all cached responses that rendered this post
$comment->delete();                        // forgets cached post listings that counted comments
```

---

## Choosing an approach

| Approach | Best for |
|---|---|
| `$request->remember()` | When you need a `build` closure and want everything in one call |
| `response()->remember()` | When the response is already built (e.g. from a service) |
| `OutputCache` injection | When you need full control or are already injecting other services |
| `#[RememberResponse]` attribute | Declarative configuration, clean controller methods, auto-registry |
