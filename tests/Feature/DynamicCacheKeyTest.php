<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Webong\Cogent\Tests\Fixtures\Models\DynamicKeyPost;
use Webong\Cogent\Tests\Fixtures\Models\User;

it('builds a cache key with an invokable key resolver', function (): void {
    $author = User::query()->create(['name' => 'Ada']);
    $post = createPosts($author, 1)->first();

    $collection = DynamicKeyPost::query()->whereKey($post->getKey())->get();
    $collection->loadCached('author');

    $connection = $post->getConnection()->getName();

    expect(Cache::has('cogent-test:dynamic:'.$connection.':'.$author->id))->toBeTrue()
        ->and($collection->first()->getRelation('author')->name)->toBe('Ada');
});

it('builds a cache key with a closure key resolver from the cached() helper', function (): void {
    $author = User::query()->create(['name' => 'Ada']);
    $post = createPosts($author, 1)->first();

    createComments($post, 2);

    $collection = DynamicKeyPost::query()->whereKey($post->getKey())->get();
    $collection->loadCached('comments');

    $connection = $post->getConnection()->getName();

    expect(Cache::has('cogent-test:dynamic-comments:'.$connection.':'.$post->id))->toBeTrue()
        ->and($collection->first()->getRelation('comments'))->toHaveCount(2);
});

it('forgets keys built by an invokable key resolver when the related model changes', function (): void {
    $author = User::query()->create(['name' => 'Ada']);
    $post = createPosts($author, 1)->first();

    DynamicKeyPost::query()->whereKey($post->getKey())->get()->loadCached('author');

    $connection = $post->getConnection()->getName();
    $key = 'cogent-test:dynamic:'.$connection.':'.$author->id;

    expect(Cache::has($key))->toBeTrue();

    $author->update(['name' => 'Grace']);

    expect(Cache::has($key))->toBeFalse();
});

it('forgets keys built by a closure key resolver when the related model changes', function (): void {
    $author = User::query()->create(['name' => 'Ada']);
    $post = createPosts($author, 1)->first();
    $comments = createComments($post, 2);

    DynamicKeyPost::query()->whereKey($post->getKey())->get()->loadCached('comments');

    $connection = $post->getConnection()->getName();
    $key = 'cogent-test:dynamic-comments:'.$connection.':'.$post->id;

    expect(Cache::has($key))->toBeTrue();

    $comments->first()->update(['body' => 'Updated']);

    expect(Cache::has($key))->toBeFalse();
});

it('reports a dynamically keyed relation as cached using the model', function (): void {
    $author = User::query()->create(['name' => 'Ada']);
    $post = createPosts($author, 1)->first();

    $collection = DynamicKeyPost::query()->whereKey($post->getKey())->get();

    expect($collection->relationCached('author', $author->id))->toBeFalse();

    $collection->loadCached('author');

    expect($collection->relationCached('author', $author->id))->toBeTrue()
        ->and($collection->relationCached('author'))->toBeTrue();
});

it('returns the cached model for a dynamically keyed relation', function (): void {
    $author = User::query()->create(['name' => 'Ada']);
    $post = createPosts($author, 1)->first();

    $collection = DynamicKeyPost::query()->whereKey($post->getKey())->get();
    $collection->loadCached('author');

    $cached = $collection->getCachedRelation('author', $author->id);

    expect($cached)->toBeInstanceOf(Model::class)
        ->and($cached->name)->toBe('Ada');
});
