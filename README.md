# Cogent

Cogent Eloquent collection extensions for Laravel. Hydrate, share, and count relations on
collections you already have in memory, without the extra queries Laravel's eager loading forces
on you.

```php
use Illuminate\Database\Eloquent\Collection;
use Webong\Cogent\Concerns\IncludesRelations;
use Webong\Cogent\Concerns\PlugsRelations;

final class ConversationCollection extends Collection
{
    use IncludesRelations;
    use PlugsRelations;
}
```

## Installation

```bash
composer require webong/cogent
```

The service provider is auto-discovered. It registers its scoped services, so nothing else is
required to get started.

## Concerns

| Concern                  | What it adds                                                                                                                 |
| ------------------------ | ---------------------------------------------------------------------------------------------------------------------------- |
| `PlugsRelations`         | `plug`, `plugMissing`, `plugAttribute`, `plugMorph`, `plugAppend`, `plugCached`, `plugCachedMissing`, `plugCachedCollection` |
| `CachesRelations`        | `withCached`, `loadCached`, `loadMissingCached`, `relationCached`, `getCachedRelation`                                       |
| `DefinesCachedRelations` | `cachedDefinition`, `cachedDefinitionFor`, on the model that declares the relations                                            |
| `GraphRelations`         | `related`, `shareRelation`                                                                                                   |
| `IncludesRelations`      | `include`, `includeMissing`, `includeMissingMorph`                                                                           |
| `BatchesRelations`       | `batchCount`, `batchAggregateCount`                                                                                          |
| `BroadcastsAsResource`   | `broadcastWith()` on a model with `BroadcastsEvents`, sending the model's resource                                          |
| `BroadcastsResources`    | `broadcastWith()` that renders an event's models through their resources                                                   |
| `SerializesPreparedModels` | a drop-in for `SerializesModels` that restores queued models through their resource plans                             |
| `PreparesResources`      | `#[Includes]`, `#[Includable]`, `#[Counts]` and a static `prepare()` hook, on a `JsonResource` or `ResourceCollection`       |

`Webong\Cogent\CogentCollection` uses every collection concern above. Extend it for your own
collections, or let Cogent wrap models in it when their collection does not use the concerns.

`IncludesRelations` builds on `GraphRelations`, so a collection that includes relations can also read
and share them. Every method on both traits works on relations that are already in memory: none of
them query on their own.

## Plugging known values

Attach data you already resolved instead of letting Eloquent query it again.

```php
$conversations->plug('channel', $channels->get($conversation->channel_id));
$conversations->plug('channel', fn (Conversation $conversation) => $channels->get($conversation->channel_id));
$conversations->plugMissing('channel', $channels); // only for relations that are not loaded yet
```

`plugMorph` fills a morph relation from type and key columns already on the model, and matches
candidates by both morph alias and raw class name so pre- and post-morph-map data both work.

```php
$images->plugMorph('imageable', 'imageable_type', 'imageable_id', $posts);
```

## Reading and sharing what is loaded

`related()` returns everything reachable through an already loaded relation path, without the models
you started from. `shareRelation()` collapses duplicate instances so the same row is never held
twice, which means mutating or comparing one copy is visible everywhere it is loaded.

```php
$comments = $conversations->related('messages.meta');
$conversations->shareRelation('channel');
```

## Cached relations

Declare a relation as cacheable on the model, right where the relation itself is defined. Cogent reads
the declaration off the relation method and derives everything else from the Eloquent relation: which
key it caches under, how a miss is resolved and how many models come back. A warm cache costs no
queries at all.

```php
use Webong\Cogent\Attributes\CachedRelation;
use Webong\Cogent\Concerns\DefinesCachedRelations;

final class Post extends Model
{
    use DefinesCachedRelations;

    #[CachedRelation(ttl: 3600)]
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class)->cached(
            ttl: 600,
            key: 'posts:{value}:comments',
            collection: CommentCollection::class,
        );
    }
}
```

`cached()` is a macro on every Eloquent relation, so the relation and the way it is cached stay in
one statement. `#[CachedRelation]` is the same declaration for the relations that need nothing but a
lifetime, and both can sit on the same method: the macro wins where they disagree.

```php
#[CachedRelation(ttl: 600)]
public function comments(): HasMany
{
    return $this->hasMany(Comment::class)->cached(collection: CommentCollection::class);
}
```

A `cached()` free function exists for the times a relation is built before it is returned. It takes
the relation as its first argument and behaves the same.

```php
use function Webong\Cogent\cached;

public function tags(): MorphMany
{
    return cached(relation: $this->morphMany(Tag::class, 'taggable'), ttl: 600);
}
```

The collection then only has to name the relation:

```php
$posts->withCached(['author', 'comments']);
$posts->loadMissingCached('author');
$posts->relationCached('author');        // warm for every model in the collection
$posts->relationCached('author', $id);   // warm for one key
$posts->getCachedRelation('author', $id);
```

A collection only needs the `CachesRelations` concern for that:

```php
final class PostCollection extends Collection
{
    use CachesRelations;
    use PlugsRelations;
}
```

### What gets derived

| relation        | cached under    | cardinality |
| --------------- | --------------- | ----------- |
| `BelongsTo`     | the foreign key | one         |
| `HasOne`        | the local key   | one         |
| `HasMany`       | the local key   | many        |
| `MorphOne`      | the local key   | one         |
| `MorphMany`     | the local key   | many        |
| `BelongsToMany` | the parent key  | many        |
| `MorphTo`       | not cacheable   |             |

Keys follow `cogent:{model}:{relation}:{value}` unless `key` says otherwise, and any template
accepts `{model}`, `{relation}` and `{value}`. Morph relations keep their type constraint, so rows
of another type can never leak into a cache. `BelongsToMany` needs the column on the pivot to be
resolvable, so pass `foreignKey` or a `resolver` to `cached()`.

A resolver receives the query Cogent already built for the relation and the keys that missed, and
returns the models keyed by the value they belong to:

```php
final class RecentCommentsResolver implements CachedRelationResolver
{
    public function resolve(Builder $query, array $localValues): Collection
    {
        return $query
            ->whereIn('post_id', $localValues)
            ->where('created_at', '>=', now()->subWeek())
            ->get()
            ->groupBy('post_id');
    }
}

public function comments(): HasMany
{
    return $this->hasMany(Comment::class)->cached(resolver: RecentCommentsResolver::class);
}
```

Constraints the relation cannot express on its own, such as `latestOfMany()`, need a resolver: the
derived query is the foreign key and the morph type, nothing more.

`cached()` is a runtime macro, so PHPStan needs to be told about it. The package ships the extension
for it; add it to your `phpstan.neon` when you analyse models that use it.

```neon
services:
    -
        class: Webong\Cogent\PhpStan\CachedRelationMacroExtension
        tags:
            - phpstan.broker.methodsClassReflectionExtension
```

### Invalidation

Because every key is derived, saving or deleting a related model forgets exactly the keys it made
stale, in the cache and in the request scoped store alike.

```php
$comment->update(['body' => 'Edited']);   // forgets the comments cached for its post
$author->update(['name' => 'Grace']);     // forgets the author cached under that user
```

A collection may still implement the deprecated `cachedRelationConfig()` to keep caches that predate
model defined ones working. Those also take part in invalidation for the keys their configuration can
derive on its own.

### Reading what was resolved

The declaration is a `CachedRelationDefinition`, readable from the model when you need it:

```php
$definition = $post->cachedDefinition('comments');

$definition->localKey;               // 'id'
$definition->relatedClass;           // Comment::class
$definition->relatedForeignKey;      // 'post_id'
$definition->ttl;                    // 600
$definition->cacheKeyFor($post->id); // 'cogent:App\Models\Post:comments:1'
```

A request-scoped store, registered as `Webong\Cogent\Support\CachedRelationAttributeStore`, keeps
every key resolved during the request in memory, so repeated calls never touch the cache twice.
Forget a key after you change the underlying row:

```php
app(CachedRelationAttributeStore::class)->forget("author:{$id}");
```

## Graphs

`include()` walks relations that are already in memory and returns one deduplicated collection of
every model it reached. `includeMissing()` does the same but queries like `loadMissing()`, batching
by model class and sharing one instance per row.

```php
$graph = $conversations->include('channel', 'participants');   // no queries
$conversations->includeMissing('channel.contacts');             // batched queries
$messages->includeMissingMorph('notifiable', [User::class => ['profile']]);
```

## Counts

`batchCount()` writes `{snake(relation)}_count` onto every model with one grouped query that reuses
the relation's own constraints, instead of a correlated subselect per row.

```php
$conversations->batchCount('messages');
```

`batchAggregateCount()` counts anything you can query, and can cap the value so a UI never has to
render an unbounded number.

```php
$conversations->batchAggregateCount(
    attribute: 'unread_messages_count',
    query: Message::query()->unread(),
    groupBy: 'conversation_id',
    cap: 100,
    constraints: fn (Builder $query) => $query->where('channel_id', $channel->id),
    truncatedAttribute: 'counts_truncated',
);
```

Use `parentKey` when the models expose the grouped value through an attribute other than their primary
key. Optional caching takes a caller-owned namespace in `cacheKey` and a Laravel cache TTL; the cache
identity also includes the parent IDs, grouped query SQL and bindings, and cap. Constraints applied by
the callback are part of that query identity. Pass `cacheContext` when a constraint depends on external
state that is not represented in the query SQL or bindings.

```php
$items->batchAggregateCount(
    attribute: 'followers_count',
    query: Followers::query(),
    groupBy: 'following_id',
    cap: null,
    parentKey: 'user_id',
    cacheKey: 'explore:followers',
    ttl: now()->addMinutes(5),
);
```

When `cap` is set, the count is clamped and the truncation flag is merged into
`truncatedAttribute[attribute]`, so several capped counts can share one attribute. The query runs
through `Webong\Cogent\Support\EloquentBatchCounter`, which never mutates the query you hand it and
returns a `BatchCountResult` for direct inspection.

## Resources

A resource declares what it renders, and Cogent loads it for every model in the collection, once,
right before the collection is resolved. Controllers stay plain Laravel and `toArray()` keeps using
`whenLoaded()` and `whenCounted()`.

```php
use Webong\Cogent\Attributes\Counts;
use Webong\Cogent\Attributes\Includes;
use Webong\Cogent\Concerns\PreparesResources;

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
            'comments_count' => $this->whenCounted('comments'),
        ];
    }
}

return PostResource::collection(Post::query()->paginate());
return Post::query()->get()->toResourceCollection(PostResource::class);
return new PostResource($post);
```

- `#[Includes]` takes relation names or dotted paths. Each relation is loaded with `includeMissing()`,
  so duplicate rows share one instance, or with `loadMissingCached()` when the model declares it
  cacheable. Relations that are already loaded are left alone.
- `#[Counts]` writes `{snake(relation)}_count` with `batchCount()`, falling back to `loadCount()` for
  relations that are not has-one-or-many. Counts that are already present are left alone.
- Map a relation to the resource that renders it and that resource's plan runs on the related models
  too, in the same pass. Without a map, Cogent finds it the way `toResource()` does: `#[UseResource]`
  on the model, then the `App\Http\Resources` naming convention.
- `collection()`, `toResourceCollection()`, paginators and `ResourceCollection` subclasses that use the
  trait are all prepared. A single resource prepares its one model.

### Requested includes

`#[Includable]` declares relations that load only when the request names them, with
`?include=author,comments.post` or `?include[]=author`. Anything not declared is ignored, so a
client can never load more than the resource allows.

```php
#[Includable(['author' => UserResource::class], 'comments')]
final class PostResource extends JsonResource
{
    use PreparesResources;
}
```

A nested path is handed to the resource that renders the relation: `?include=author.posts` loads
`author` here and asks `UserResource` for `posts`, which it loads only if it declares
`#[Includable('posts')]` itself. Read another parameter than `include` from a service provider:

```php
Cogent::includeParameter('with');
```

For anything the attributes cannot say, add a static `prepare()`. It runs once per resolve, with every
model in a `CogentCollection`:

```php
public static function prepare(CogentCollection $posts, Request $request): void
{
    $posts->plugMissing('channel', $request->user()->channels);
}
```

### Caching the output

`#[Remember]` caches what a resource renders, or call `remember()` on a resource or collection. A
hit skips preparing and `toArray()` entirely, so the only query left is the one that fetched the
models.

```php
#[Remember(300)]
#[Includes(['author' => UserResource::class])]
#[Counts('comments')]
final class PostResource extends JsonResource
{
    use PreparesResources;

    // Optional: anything else the output depends on.
    public static function cacheVary(Request $request): array
    {
        return ['locale' => app()->getLocale()];
    }
}

return PostResource::collection($posts);                   // #[Remember] on the resource
return PostResource::collection($posts)->remember(60);     // or per call
```

Every authenticated user gets their own entry. Output that is the same for everyone can share one
with `#[Remember(300, shared: true)]` or `remember(60, shared: true)`.

An entry is keyed on the models rendered, in order, the requested includes, the user unless it is
shared, and `cacheVary()`. It is
forgotten when any of these is saved or deleted through Eloquent:

- a model it rendered, or one reached through a loaded relation;
- a row a has-one or has-many relation, or a `#[Counts]` count, read: a new comment forgets the post
  that counted its comments.

Which columns matter for each model class is kept in the cache, so a save in any later request knows
what to forget; saving a model nobody rendered costs one cache read. Changes Eloquent does not see,
such as query builder updates and pivot changes, and counts loaded outside the resource with
`withCount()`, are left to the TTL.

## Broadcasting

`BroadcastsResources` builds `broadcastWith()` from an event's public properties, as Laravel does
when an event has none, but renders models and Eloquent collections through their resources. Clients
get the same shape over a websocket as over HTTP, and resources that use `PreparesResources` run
their plan before the payload is built.

```php
use Webong\Cogent\Attributes\WithResource;
use Webong\Cogent\Concerns\BroadcastsResources;

final class PostUpdated implements ShouldBroadcast
{
    use BroadcastsResources;
    use SerializesModels;

    public function __construct(
        public Post $post,                                         // #[UseResource] or App\Http\Resources\PostResource
        #[WithResource(EditorResource::class)] public User $editor,
    ) {}
}
```

Models without a resource fall back to `toArray()`.

For models that broadcast their own events with Laravel's `BroadcastsEvents`, add
`BroadcastsAsResource` and every model event is sent as the model's resource:

```php
#[UseResource(PostResource::class)]
class Post extends Model
{
    use BroadcastsAsResource;
    use BroadcastsEvents;
}
```

## Queued events and jobs

Laravel's `SerializesModels` queries every restored model again and reloads its relations one by
one, so counts and cached relations are lost on the way through the queue. Swap it for
`SerializesPreparedModels` and restored models load what their resource renders, while the relations
they had are read from the cache when they are cacheable:

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

A restored event then builds its broadcast payload without another query. `#[WithResource]` on a
property runs that resource's plan too.

## Testing

```bash
composer install
composer test
composer lint
composer analyse
```

## License

MIT.
