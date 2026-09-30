<?php

declare(strict_types=1);

namespace Webong\Cogent\Tests\Fixtures\Collections;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Webong\Cogent\Concerns\CachesRelations;
use Webong\Cogent\Concerns\PlugsRelations;
use Webong\Cogent\Tests\Fixtures\Models\Comment;
use Webong\Cogent\Tests\Fixtures\Models\LegacyCachedPost;
use Webong\Cogent\Tests\Fixtures\Models\User;

/**
 * @template TModel of LegacyCachedPost
 *
 * @extends Collection<int, TModel>
 */
final class LegacyCachedPostCollection extends Collection
{
    use CachesRelations;
    use PlugsRelations;

    /**
     * @return array<string, mixed>
     *
     * @deprecated Declare caches on the model with #[CachedRelation] or cached() instead.
     */
    protected function cachedRelationConfig(string $relation): array
    {
        return match ($relation) {
            'author' => [
                'localKey' => 'user_id',
                'relatedClass' => User::class,
                'resolver' => static fn (array $ids): SupportCollection => User::query()
                    ->whereIn('id', $ids)
                    ->get()
                    ->keyBy('id'),
                'cacheKey' => static fn (mixed $id): string => 'cogent-legacy:author:'.$id,
                'ttl' => 60,
            ],
            'comments' => [
                'localKey' => 'id',
                'relatedClass' => Comment::class,
                'resolver' => static fn (array $ids): SupportCollection => Comment::query()
                    ->whereIn('post_id', $ids)
                    ->get()
                    ->groupBy('post_id'),
                'cacheKey' => static fn (mixed $id): string => 'cogent-legacy:comments:'.$id,
                'ttl' => 60,
                'collection' => true,
                'collectionClass' => CommentCollection::class,
            ],
            default => throw new \InvalidArgumentException(sprintf(
                'No cached relation is configured for [%s].',
                $relation,
            )),
        };
    }
}
