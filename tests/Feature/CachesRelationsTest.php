<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Webong\Fluent\Tests\Fixtures\Collections\CachedPostCollection;
use Webong\Fluent\Tests\Fixtures\Collections\CommentCollection;
use Webong\Fluent\Tests\Fixtures\Models\CachedPost;
use Webong\Fluent\Tests\Fixtures\Models\User;

it('loads cached relations for every model in one pass', function (): void {
    $author = User::query()->create(['name' => 'Ada']);
    $posts = createPosts($author, 2);

    createComments($posts->first(), 2);

    $collection = CachedPost::query()->whereKey($posts->modelKeys())->orderBy('id')->get();

    $queries = $this->countQueries();

    expect($collection->withCached(['author', 'comments']))->toBe($collection)
        ->and($queries())->toBe(2)
        ->and($collection->every(fn (CachedPost $post): bool => $post->relationLoaded('author')))->toBeTrue()
        ->and($collection->first()->getRelation('author')->name)->toBe('Ada')
        ->and($collection->first()->getRelation('comments'))->toBeInstanceOf(CommentCollection::class)
        ->and($collection->first()->getRelation('comments'))->toHaveCount(2)
        ->and($collection->last()->getRelation('comments'))->toBeInstanceOf(CommentCollection::class)
        ->and($collection->last()->getRelation('comments')->isEmpty())->toBeTrue()
        ->and($collection->first()->getRelation('author'))
        ->toBe($collection->last()->getRelation('author'));
});

it('caches the attributes of loaded relations', function (): void {
    $author = User::query()->create(['name' => 'Ada']);
    $posts = createPosts($author, 1);

    CachedPost::query()->whereKey($posts->modelKeys())->get()->loadCached('author');

    expect(Cache::get('fluent-test:author:'.$author->id))->toEqual($author->getAttributes())
        ->and(Cache::get('fluent-test:comments:'.$posts->first()->id))->toBeNull();
});

it('keeps relations that are already loaded when loading missing cached relations', function (): void {
    $author = User::query()->create(['name' => 'Ada']);
    $posts = createPosts($author, 2);

    $preloaded = User::query()->create(['name' => 'Grace']);

    $collection = CachedPost::query()->whereKey($posts->modelKeys())->orderBy('id')->get();
    $collection->first()->setRelation('author', $preloaded);

    $queries = $this->countQueries();

    $collection->loadMissingCached('author');

    expect($queries())->toBe(1)
        ->and($collection->first()->getRelation('author'))->toBe($preloaded)
        ->and($collection->last()->getRelation('author')->name)->toBe('Ada');
});

it('reports whether cached relations are warm for a key or for the whole collection', function (): void {
    $author = User::query()->create(['name' => 'Ada']);
    $posts = createPosts($author, 2);

    $collection = CachedPost::query()->whereKey($posts->modelKeys())->orderBy('id')->get();

    expect($collection->relationCached('author'))->toBeFalse()
        ->and($collection->relationCached('author', $author->id))->toBeFalse();

    $collection->loadCached('author');

    expect($collection->relationCached('author'))->toBeTrue()
        ->and($collection->relationCached('author', $author->id))->toBeTrue()
        ->and($collection->relationCached('author', 999999))->toBeFalse();
});

it('returns a single hydrated model for a cached key', function (): void {
    $author = User::query()->create(['name' => 'Ada']);
    $posts = createPosts($author, 1);

    CachedPost::query()->whereKey($posts->modelKeys())->get()->loadCached('author');

    $collection = CachedPost::query()->whereKey($posts->modelKeys())->get();

    $cached = $collection->getCachedRelation('author', $author->id);

    expect($cached)->toBeInstanceOf(User::class)
        ->and($cached->name)->toBe('Ada')
        ->and($collection->getCachedRelation('author', 999999))->toBeNull();
});

it('refuses relations that have no cached configuration', function (): void {
    $author = User::query()->create(['name' => 'Ada']);
    $posts = createPosts($author, 1);

    $collection = CachedPost::query()->whereKey($posts->modelKeys())->get();

    expect(fn () => $collection->loadCached('tags'))
        ->toThrow(InvalidArgumentException::class, 'No cached relation is configured for [tags].')
        ->and(fn () => $collection->loadMissingCached('tags'))
        ->toThrow(InvalidArgumentException::class, 'No cached relation is configured for [tags].')
        ->and(fn () => $collection->relationCached('tags'))
        ->toThrow(InvalidArgumentException::class, 'No cached relation is configured for [tags].');
});

it('does not touch the database for an empty collection', function (): void {
    $collection = new CachedPostCollection;

    $queries = $this->countQueries();

    expect($collection->loadCached('author'))->toBe($collection)
        ->and($collection->loadMissingCached('comments'))->toBe($collection)
        ->and($collection->relationCached('author'))->toBeFalse()
        ->and($queries())->toBe(0);
});
