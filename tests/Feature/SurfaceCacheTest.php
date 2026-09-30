<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Webong\Cogent\Cache\CacheKeyBuilder;
use Webong\Cogent\Cache\SurfaceCache;

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
