<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Webong\Fluent\Support\CachedRelationAttributeStore;
use Webong\Fluent\Tests\Fixtures\Collections\CachedPostCollection;
use Webong\Fluent\Tests\Fixtures\Collections\CommentCollection;
use Webong\Fluent\Tests\Fixtures\Models\CachedPost;
use Webong\Fluent\Tests\Fixtures\Models\Comment;
use Webong\Fluent\Tests\Fixtures\Models\LegacyCachedPost;
use Webong\Fluent\Tests\Fixtures\Models\Tag;
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
        ->and($collection->getCachedRelation('author', 999999))->toBeNull()
        ->and($collection->getCachedRelation('comments', $posts->first()->id))->toBeNull();
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
        ->toThrow(InvalidArgumentException::class, 'No cached relation is configured for [tags].')
        ->and(fn () => $collection->loadCached('uncachedComments'))
        ->toThrow(InvalidArgumentException::class, 'No cached relation is configured for [uncachedComments].');
});

it('does not touch the database for an empty collection', function (): void {
    $collection = new CachedPostCollection;

    $queries = $this->countQueries();

    expect($collection->loadCached('author'))->toBe($collection)
        ->and($collection->loadMissingCached('comments'))->toBe($collection)
        ->and($collection->relationCached('author'))->toBeFalse()
        ->and($queries())->toBe(0);
});

it('reads the cached configuration off the model', function (): void {
    $definition = (new CachedPost)->cachedDefinition('author');

    expect($definition->modelClass)->toBe(CachedPost::class)
        ->and($definition->relation)->toBe('author')
        ->and($definition->localKey)->toBe('user_id')
        ->and($definition->relatedClass)->toBe(User::class)
        ->and($definition->relatedKey)->toBe('id')
        ->and($definition->many)->toBeFalse()
        ->and($definition->ttl)->toBe(60)
        ->and($definition->cacheKeyFor(7))->toBe('fluent-test:author:7')
        ->and((new CachedPost)->cachedDefinitionFor('uncachedComments'))->toBeNull();
});

it('derives a has many relation down to the key it caches under', function (): void {
    $definition = (new CachedPost)->cachedDefinition('comments');

    expect($definition->many)->toBeTrue()
        ->and($definition->localKey)->toBe('id')
        ->and($definition->relatedClass)->toBe(Comment::class)
        ->and($definition->relatedForeignKey)->toBe('post_id')
        ->and($definition->collectionClass)->toBe(CommentCollection::class)
        ->and($definition->ttl)->toBe(60);
});

it('falls back to a cache key built from the model and the relation', function (): void {
    $author = User::query()->create(['name' => 'Ada']);

    createPosts($author, 1);

    expect((new User)->cachedDefinition('posts')->cacheKeyFor($author->id))
        ->toBe('fluent:'.User::class.':posts:'.$author->id);
});

it('caches a has one relation as a single model', function (): void {
    $author = User::query()->create(['name' => 'Ada']);
    $post = createPosts($author, 1)->first();

    createComments($post, 2);

    $queries = $this->countQueries();

    $collection = CachedPost::query()->whereKey($post->getKey())->get();
    $collection->loadCached('firstComment');

    expect($queries())->toBe(2)
        ->and($collection->first()->getRelation('firstComment'))->toBeInstanceOf(Comment::class)
        ->and($collection->first()->getRelation('firstComment')->body)->toBe('Comment #1');

    $warm = CachedPost::query()->whereKey($post->getKey())->get();
    $warm->loadCached('firstComment');

    expect($queries())->toBe(3)
        ->and($warm->first()->getRelation('firstComment')->body)->toBe('Comment #1');
});

it('leaves a has one relation without a row uncached', function (): void {
    $author = User::query()->create(['name' => 'Ada']);
    $post = createPosts($author, 1)->first();

    $collection = CachedPost::query()->whereKey($post->getKey())->get();
    $collection->loadCached('firstComment');

    expect($collection->first()->getRelation('firstComment'))->toBeNull()
        ->and($collection->relationCached('firstComment', $post->id))->toBeFalse();
});

it('caches a morph relation behind its own type constraint', function (): void {
    $author = User::query()->create(['name' => 'Ada']);
    $post = createPosts($author, 1)->first();

    Tag::query()->create([
        'taggable_type' => $post->getMorphClass(),
        'taggable_id' => $post->id,
        'name' => 'eloquent',
    ]);
    Tag::query()->create([
        'taggable_type' => $author->getMorphClass(),
        'taggable_id' => $author->id,
        'name' => 'collections',
    ]);

    $users = User::query()->whereKey($author->getKey())->get();

    $users->loadCached('tags');

    expect($users->first()->getRelation('tags'))->toHaveCount(1)
        ->and($users->first()->getRelation('tags')->first()->name)->toBe('collections')
        ->and((new User)->cachedDefinition('tags')->relatedMorphType)->toBe('taggable_type')
        ->and((new User)->cachedDefinition('tags')->morphClass)->toBe(User::class);
});

it('forgets the cached author when that author is saved', function (): void {
    $author = User::query()->create(['name' => 'Ada']);
    $posts = createPosts($author, 1);

    CachedPost::query()->whereKey($posts->modelKeys())->get()->loadCached('author');

    expect(Cache::has('fluent-test:author:'.$author->id))->toBeTrue();

    $author->update(['name' => 'Grace']);

    $collection = CachedPost::query()->whereKey($posts->modelKeys())->get();

    expect($collection->relationCached('author'))->toBeFalse()
        ->and(app(CachedRelationAttributeStore::class)->has('fluent-test:author:'.$author->id))->toBeFalse();

    $collection->loadCached('author');

    expect($collection->first()->getRelation('author')->name)->toBe('Grace');
});

it('forgets the cached collection when one of its rows changes', function (): void {
    $author = User::query()->create(['name' => 'Ada']);
    $post = createPosts($author, 1)->first();

    $comments = createComments($post, 1);

    CachedPost::query()->whereKey($post->getKey())->get()->loadCached('comments');

    expect(Cache::has('fluent-test:comments:'.$post->id))->toBeTrue();

    $comments->first()->update(['body' => 'Edited']);

    $collection = CachedPost::query()->whereKey($post->getKey())->get();

    expect($collection->relationCached('comments'))->toBeFalse();

    $collection->loadCached('comments');

    expect($collection->first()->getRelation('comments')->first()->body)->toBe('Edited');
});

it('forgets the cached collection when one of its rows is deleted', function (): void {
    $author = User::query()->create(['name' => 'Ada']);
    $post = createPosts($author, 1)->first();

    $comments = createComments($post, 2);

    CachedPost::query()->whereKey($post->getKey())->get()->loadCached('comments');

    $comments->first()->delete();

    $collection = CachedPost::query()->whereKey($post->getKey())->get();

    expect($collection->relationCached('comments'))->toBeFalse();

    $collection->loadCached('comments');

    expect($collection->first()->getRelation('comments'))->toHaveCount(1);
});

it('caches a relation declared with the helper the same as the one declared on the relation', function (): void {
    $author = User::query()->create(['name' => 'Ada']);
    $post = createPosts($author, 1)->first();

    createComments($post, 2);

    $collection = CachedPost::query()->whereKey($post->getKey())->get();

    $queries = $this->countQueries();

    $collection->withCached(['comments', 'commentsThroughTheHelper']);

    expect($queries())->toBe(2)
        ->and($collection->first()->getRelation('commentsThroughTheHelper'))->toHaveCount(2)
        ->and($collection->first()->getRelation('commentsThroughTheHelper'))
        ->toBeInstanceOf(CommentCollection::class)
        ->and($collection->relationCached('commentsThroughTheHelper', $post->id))->toBeTrue()
        ->and(Cache::has('fluent-test:helper-comments:'.$post->id))->toBeTrue();
});

it('still reads caches declared on the collection itself', function (): void {
    $author = User::query()->create(['name' => 'Ada']);
    $posts = createPosts($author, 1);

    createComments($posts->first(), 1);

    $collection = LegacyCachedPost::query()->whereKey($posts->modelKeys())->get();

    $queries = $this->countQueries();

    $collection->withCached(['author', 'comments']);

    expect($queries())->toBe(2)
        ->and($collection->first()->getRelation('author')->name)->toBe('Ada')
        ->and($collection->first()->getRelation('comments'))->toHaveCount(1)
        ->and($collection->relationCached('author', $author->id))->toBeTrue()
        ->and(Cache::has('fluent-legacy:author:'.$author->id))->toBeTrue();

    $author->update(['name' => 'Grace']);

    expect($collection->relationCached('author'))->toBeFalse();
});
