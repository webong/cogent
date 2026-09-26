# Fluent

Fluent Eloquent collection extensions for Laravel. Hydrate, share, and count relations on
collections you already have in memory, without the extra queries Laravel's eager loading forces
on you.

```php
use Illuminate\Database\Eloquent\Collection;
use Webong\Fluent\Concerns\IncludesRelations;
use Webong\Fluent\Concerns\PlugsRelations;

final class ConversationCollection extends Collection
{
    use IncludesRelations;
    use PlugsRelations;
}
```

## Installation

```bash
composer require webong/fluent
```

The service provider is auto-discovered. It registers two scoped services, so nothing else is
required to get started.

## Concerns

| Concern             | What it adds                                                                                                                 |
| ------------------- | ---------------------------------------------------------------------------------------------------------------------------- |
| `PlugsRelations`    | `plug`, `plugMissing`, `plugAttribute`, `plugMorph`, `plugAppend`, `plugCached`, `plugCachedMissing`, `plugCachedCollection` |
| `CachesRelations`   | `withCached`, `loadCached`, `loadMissingCached`, `relationCached`, `getCachedRelation`                                       |
| `GraphRelations`    | `related`, `shareRelation`                                                                                                   |
| `IncludesRelations` | `include`, `includeMissing`, `includeMissingMorph`                                                                           |
| `BatchesRelations`  | `loadCounts`, `loadAggregateCounts`                                                                                          |

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

Declare a relation once, then hydrate it from cache with a single batched query on a miss. Cached
values are stored as raw attributes, so a warm cache costs no queries at all.

```php
use Illuminate\Support\Collection as SupportCollection;

final class CachedPostCollection extends Collection
{
    use \Webong\Fluent\Concerns\CachesRelations;
    use \Webong\Fluent\Concerns\PlugsRelations;

    protected function cachedRelationConfig(string $relation): array
    {
        return match ($relation) {
            'author' => [
                'localKey' => 'user_id',
                'relatedClass' => User::class,
                'resolver' => fn (array $ids): SupportCollection => User::query()
                    ->whereIn('id', $ids)
                    ->get()
                    ->keyBy('id'),
                'cacheKey' => fn (mixed $id): string => "author:{$id}",
                'ttl' => now()->addDay(),
            ],
            'comments' => [
                'localKey' => 'id',
                'relatedClass' => Comment::class,
                'resolver' => fn (array $ids): SupportCollection => Comment::query()
                    ->whereIn('post_id', $ids)
                    ->get()
                    ->groupBy('post_id'),
                'cacheKey' => fn (mixed $id): string => "comments:{$id}",
                'ttl' => now()->addMinutes(30),
                'collection' => true,
                'collectionClass' => CommentCollection::class,
            ],
            default => throw new InvalidArgumentException("No cached relation is configured for [{$relation}]."),
        };
    }
}
```

```php
$posts->withCached(['author', 'comments']);
$posts->loadMissingCached('author');
$posts->relationCached('author');        // warm for every model in the collection
$posts->relationCached('author', $id);   // warm for one key
$posts->getCachedRelation('author', $id);
```

A request-scoped store, registered as `Webong\Fluent\Support\CachedRelationAttributeStore`, keeps
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

`loadCounts()` batch-loads `{snake(relation)}_count` with one grouped query that reuses the
relation's own constraints, instead of a correlated subselect per row.

```php
$conversations->loadCounts('messages');
```

`loadAggregateCounts()` counts anything you can query, and can cap the value so a UI never has to
render an unbounded number.

```php
$conversations->loadAggregateCounts(
    attribute: 'unread_messages_count',
    query: Message::query()->unread(),
    groupBy: 'conversation_id',
    cap: 100,
    constraints: fn (Builder $query) => $query->where('channel_id', $channel->id),
    truncatedAttribute: 'counts_truncated',
);
```

When `cap` is set, the count is clamped and the truncation flag is merged into
`truncatedAttribute[attribute]`, so several capped counts can share one attribute. The query runs
through `Webong\Fluent\Support\EloquentBatchCounter`, which never mutates the query you hand it and
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
