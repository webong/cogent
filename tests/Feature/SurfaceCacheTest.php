<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Webong\Cogent\Cache\CacheKeyBuilder;
use Webong\Cogent\Cache\SurfaceCache;

it('preserves zero and empty key segments', function (array $segments, string $expected): void {
    expect((new CacheKeyBuilder)->build('reports', 'exports', 'list', [], $segments))
        ->toBe($expected);
})->with([
    'named zero' => [['user' => 0], 'reports:user:0:exports:list'],
    'positional zero' => [['0'], 'reports:0:exports:list'],
    'empty value' => [['search' => ''], 'reports:search::exports:list'],
]);

it('reads and writes a custom payload format', function (bool $taggable): void {
    $repository = new class (new ArrayStore) extends Repository {
        public bool $taggable = true;

        public function supportsTags(): bool
        {
            return $this->taggable;
        }
    };
    $repository->taggable = $taggable;
    $storage = $taggable ? $repository->tags(['reports']) : $repository;
    $storage->put('legacy', ['__reports_payload_v1' => true, 'value' => null], 60);
    $cache = new SurfaceCache($repository, payloadMarker: '__reports_payload_v1');

    $result = $cache->remember('legacy', ['reports'], 60, fn (): string => 'unexpected');
    $cache->remember('fresh', ['reports'], 60, fn (): string => 'report');

    expect($result->hit)->toBeTrue()
        ->and($result->value)->toBeNull()
        ->and($storage->get('fresh'))->toBe(['__reports_payload_v1' => true, 'value' => 'report']);
})->with(['tagged' => true, 'tagless' => false]);

it('uses custom fallback index and lock prefixes', function (): void {
    $indexKey = 'reports:index:'.sha1('reports');
    $store = Mockery::mock(ArrayStore::class)->makePartial();
    $store->shouldReceive('lock')
        ->once()
        ->with('reports:index-lock:'.sha1($indexKey), 360)
        ->passthru();
    $repository = new class ($store) extends Repository {
        public function supportsTags(): bool
        {
            return false;
        }
    };
    $cache = new SurfaceCache($repository, indexPrefix: 'reports:index:', lockPrefix: 'reports:index-lock:');
    $cache->remember('report', ['reports'], 60, fn (): string => 'payload');

    expect($repository->get($indexKey))->toBe(['report'])
        ->and($repository->get('report'))->toBe(['__cogent_surface_cache_payload_v1' => true, 'value' => 'payload']);

    $cache->invalidateTags(['reports']);

    expect($repository->get('report'))->toBeNull()
        ->and($repository->get($indexKey))->toBeNull();
});

it('reuses a surface payload for the same cache key', function (): void {
    $cache = new SurfaceCache(Cache::store());
    $callbacks = 0;

    $first = $cache->remember('inbox:conversations:list', ['inbox:surface:list'], 60, function () use (&$callbacks): array {
        $callbacks++;

        return ['version' => 1];
    });
    $second = $cache->remember('inbox:conversations:list', ['inbox:surface:list'], 60, function () use (&$callbacks): array {
        $callbacks++;

        return ['version' => 2];
    });

    expect($first->value)->toBe(['version' => 1])
        ->and($first->hit)->toBeFalse()
        ->and($second->value)->toBe(['version' => 1])
        ->and($second->hit)->toBeTrue()
        ->and($callbacks)->toBe(1);
});

it('treats a cached null payload as a hit', function (): void {
    $cache = new SurfaceCache(Cache::store());
    $callbacks = 0;

    $cache->remember('inbox:conversations:empty', ['inbox:surface:list'], 60, function () use (&$callbacks): null {
        $callbacks++;

        return null;
    });
    $result = $cache->remember('inbox:conversations:empty', ['inbox:surface:list'], 60, function () use (&$callbacks): string {
        $callbacks++;

        return 'should not run';
    });

    expect($result->value)->toBeNull()
        ->and($result->hit)->toBeTrue()
        ->and($callbacks)->toBe(1);
});

it('invalidates every payload assigned to a tag', function (): void {
    $cache = new SurfaceCache(Cache::store());
    $callbacks = 0;

    $cache->remember('inbox:conversations:one', ['inbox:conversation:1'], 60, function () use (&$callbacks): array {
        $callbacks++;

        return ['version' => 1];
    });
    $cache->remember('inbox:conversations:two', ['inbox:conversation:1'], 60, function () use (&$callbacks): array {
        $callbacks++;

        return ['version' => 1];
    });
    $cache->invalidateTags(['inbox:conversation:1']);

    $first = $cache->remember('inbox:conversations:one', ['inbox:conversation:1'], 60, function () use (&$callbacks): array {
        $callbacks++;

        return ['version' => 2];
    });
    $second = $cache->remember('inbox:conversations:two', ['inbox:conversation:1'], 60, function () use (&$callbacks): array {
        $callbacks++;

        return ['version' => 2];
    });

    expect($first->value)->toBe(['version' => 2])
        ->and($second->value)->toBe(['version' => 2])
        ->and($callbacks)->toBe(4);
});

it('builds stable keys for equivalent parameters', function (): void {
    $keys = new CacheKeyBuilder;

    $first = $keys->build('inbox', 'conversations', 'list', [
        'filters' => ['status' => 'open', 'teams' => [3, 1]],
        'page' => 1,
    ], ['user' => 7]);
    $second = $keys->build('inbox', 'conversations', 'list', [
        'page' => 1,
        'filters' => ['teams' => [1, 3], 'status' => 'open'],
    ], ['user' => 7]);

    expect($first)->toBe($second)
        ->and($first)->toStartWith('inbox:user:7:conversations:list:');
});
