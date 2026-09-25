<?php

declare(strict_types=1);

use Webong\Fluent\FluentServiceProvider;
use Webong\Fluent\Support\CachedRelationAttributeStore;
use Webong\Fluent\Support\EloquentBatchCounter;

it('registers the package provider', function (): void {
    expect(app()->getProvider(FluentServiceProvider::class))->toBeInstanceOf(FluentServiceProvider::class);
});

it('shares a single cached relation store within a request', function (): void {
    $store = app(CachedRelationAttributeStore::class);

    $store->put('fluent-test:key', ['id' => 1]);

    expect(app(CachedRelationAttributeStore::class))->toBe($store)
        ->and($store->has('fluent-test:key'))->toBeTrue()
        ->and($store->get('fluent-test:key'))->toBe(['id' => 1])
        ->and($store->get('fluent-test:missing'))->toBeNull();

    $store->forget('fluent-test:key');

    expect($store->has('fluent-test:key'))->toBeFalse();
});

it('resolves the batch counter from the container', function (): void {
    expect(app(EloquentBatchCounter::class))->toBeInstanceOf(EloquentBatchCounter::class)
        ->and(app(EloquentBatchCounter::class))->toBe(app(EloquentBatchCounter::class));
});
