<?php

declare(strict_types=1);

namespace Webong\Cogent\Cache;

use Closure;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use stdClass;

final class SurfaceCache
{
    private const CACHE_VALUE_MARKER = '__cogent_surface_cache_payload_v1';

    public function __construct(
        private readonly Repository $cache,
        private readonly string $indexPrefix = 'cogent:surface-cache:index:',
        private readonly string $payloadMarker = self::CACHE_VALUE_MARKER,
        private readonly ?string $lockPrefix = null,
    ) {
    }

    /**
     * @param  array<int, string>  $tags
     */
    public function remember(string $key, array $tags, int $ttl, Closure $callback): CacheResult
    {
        if ($ttl <= 0) {
            return new CacheResult($callback(), false);
        }

        $tags = array_values(array_unique($tags));
        $cacheMissSentinel = new stdClass;

        if ($this->cache->supportsTags()) {
            $taggedCache = $this->cache->tags($tags);
            $cachedPayload = $taggedCache->get($key, $cacheMissSentinel);

            if ($cachedPayload !== $cacheMissSentinel) {
                return new CacheResult($this->unwrapCachedPayload($cachedPayload), true);
            }

            $value = $callback();
            $taggedCache->put($key, $this->wrapCachedPayload($value), $ttl);

            return new CacheResult($value, false);
        }

        $cachedPayload = $this->cache->get($key, $cacheMissSentinel);

        if ($cachedPayload !== $cacheMissSentinel) {
            return new CacheResult($this->unwrapCachedPayload($cachedPayload), true);
        }

        $value = $callback();
        $this->cache->put($key, $this->wrapCachedPayload($value), $ttl);
        $this->registerFallbackIndexes($key, $tags, $ttl);

        return new CacheResult($value, false);
    }

    /**
     * @param  array<int, string>  $tags
     */
    public function invalidateTags(array $tags): void
    {
        $tags = array_values(array_unique($tags));

        if ($tags === []) {
            return;
        }

        if ($this->cache->supportsTags()) {
            $this->cache->tags($tags)->flush();

            return;
        }

        $keys = [];

        foreach ($tags as $tag) {
            /** @var array<int, string> $indexedKeys */
            $indexedKeys = $this->cache->get($this->fallbackIndexKey($tag), []);
            $keys = [...$keys, ...$indexedKeys];
        }

        foreach (array_unique($keys) as $key) {
            $this->cache->forget($key);
        }

        foreach ($tags as $tag) {
            $this->cache->forget($this->fallbackIndexKey($tag));
        }
    }

    /**
     * @param  array<int, string>  $tags
     */
    private function registerFallbackIndexes(string $key, array $tags, int $ttl): void
    {
        foreach ($tags as $tag) {
            $indexKey = $this->fallbackIndexKey($tag);
            $lockName = ($this->lockPrefix ?? $this->indexPrefix.'lock:').sha1($indexKey);
            $lockTtl = max(5, $ttl + 300);
            $store = $this->cache instanceof CacheRepository ? $this->cache->getStore() : null;

            if (! $store instanceof LockProvider) {
                $this->addFallbackIndexKey($indexKey, $key, $ttl);

                continue;
            }

            try {
                $store->lock($lockName, $lockTtl)->block(5, function () use ($indexKey, $key, $ttl): void {
                    $this->addFallbackIndexKey($indexKey, $key, $ttl);
                });
            } catch (LockTimeoutException) {
                $this->addFallbackIndexKey($indexKey, $key, $ttl);
            }
        }
    }

    private function addFallbackIndexKey(string $indexKey, string $key, int $ttl): void
    {
        /** @var array<int, string> $existingKeys */
        $existingKeys = $this->cache->get($indexKey, []);
        $existingKeys[] = $key;

        $this->cache->put($indexKey, array_values(array_unique($existingKeys)), $ttl + 300);
    }

    private function fallbackIndexKey(string $tag): string
    {
        return $this->indexPrefix.sha1($tag);
    }

    /**
     * @return array<string, mixed>
     */
    private function wrapCachedPayload(mixed $value): array
    {
        return [
            $this->payloadMarker => true,
            'value' => $value,
        ];
    }

    private function unwrapCachedPayload(mixed $payload): mixed
    {
        if (is_array($payload)
            && ($payload[$this->payloadMarker] ?? false) === true
            && array_key_exists('value', $payload)) {
            return $payload['value'];
        }

        return $payload;
    }
}
