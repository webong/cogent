<?php

declare(strict_types=1);

use Webong\Cogent\CogentServiceProvider;
use Webong\Cogent\Support\CachedRelationAttributeStore;
use Webong\Cogent\Support\EloquentBatchCounter;

it('registers the package provider', function (): void {
    expect(app()->getProvider(CogentServiceProvider::class))->toBeInstanceOf(CogentServiceProvider::class);
});

it('shares a single cached relation store within a request', function (): void {
    $store = app(CachedRelationAttributeStore::class);

    $store->put('cogent-test:key', ['id' => 1]);

    expect(app(CachedRelationAttributeStore::class))->toBe($store)
        ->and($store->has('cogent-test:key'))->toBeTrue()
        ->and($store->get('cogent-test:key'))->toBe(['id' => 1])
        ->and($store->get('cogent-test:missing'))->toBeNull();

    $store->forget('cogent-test:key');

    expect($store->has('cogent-test:key'))->toBeFalse();
});

it('resolves the batch counter from the container', function (): void {
    expect(app(EloquentBatchCounter::class))->toBeInstanceOf(EloquentBatchCounter::class)
        ->and(app(EloquentBatchCounter::class))->toBe(app(EloquentBatchCounter::class));
});
