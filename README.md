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

The service provider is auto-discovered. It registers two scoped services, so nothing else is
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

## Testing

```bash
composer install
composer test
composer lint
composer analyse
```

## License

MIT.
