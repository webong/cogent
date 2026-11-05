<?php

declare(strict_types=1);

namespace Webong\Cogent\Cache;

/**
 * Invalidates Cogent cache entries by domain, surface, or tag.
 *
 * Mirrors the CRM's InboxCacheInvalidator so both packages share the same
 * mental model for cache invalidation.
 *
 * Example:
 *
 *     $invalidator->invalidateUser($userId);
 *     $invalidator->invalidatePost($postId);
 *     $invalidator->invalidateSurface('list');
 */
final class CogentCacheInvalidator
{
    public function __construct(
        private readonly \Illuminate\Contracts\Cache\Repository $cache,
        private readonly CogentCacheNameBuilder $keyBuilder,
    ) {}

    public function invalidateUser(string|int $userId): void
    {
        $this->cache->invalidateTags([
            $this->keyBuilder->getDomainTag('users', $userId),
        ]);
    }

    public function invalidatePost(string|int $postId): void
    {
        $this->cache->invalidateTags([
            $this->keyBuilder->getDomainTag('posts', $postId),
            $this->keyBuilder->getDomainSurfaceTag('posts', 'detail'),
            $this->keyBuilder->getSurfaceTag('list'),
            $this->keyBuilder->getSurfaceTag('counts'),
        ]);
    }

    public function invalidateComment(string|int $commentId): void
    {
        $this->cache->invalidateTags([
            $this->keyBuilder->getDomainTag('comments', $commentId),
            $this->keyBuilder->getSurfaceTag('list'),
            $this->keyBuilder->getSurfaceTag('counts'),
        ]);
    }

    public function invalidateSurface(string $surface): void
    {
        $this->cache->invalidateTags([
            $this->keyBuilder->getSurfaceTag($surface),
        ]);
    }

    public function invalidateDomain(string $domain): void
    {
        $registry = config('cogent.registry', []);
        $surfaces = $registry[$domain] ?? [];

        $tags = [];

        foreach ($surfaces as $surface) {
            $tags[] = $this->keyBuilder->getDomainSurfaceTag($domain, $surface);
        }

        $tags[] = $this->keyBuilder->getSurfaceTag('list');
        $tags[] = $this->keyBuilder->getSurfaceTag('counts');

        $this->cache->invalidateTags($tags);
    }
}
