<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Webong\Fluent\Tests\Fixtures\Collections\CommentCollection;
use Webong\Fluent\Tests\Fixtures\Models\Comment;
use Webong\Fluent\Tests\Fixtures\Models\Image;
use Webong\Fluent\Tests\Fixtures\Models\Post;
use Webong\Fluent\Tests\Fixtures\Models\User;

it('plugs a relation onto every model in the collection', function (): void {
    $author = User::query()->create(['name' => 'Ada']);
    $posts = createPosts($author, 2);

    $collection = Post::query()->whereKey($posts->modelKeys())->get();

    expect($collection->plug('author', $author))->toBe($collection)
        ->and($collection->every(fn (Post $post): bool => $post->relationLoaded('author')))->toBeTrue()
        ->and($collection->first()->getRelation('author'))->toBe($author);
});

it('resolves a closure per model when plugging a relation', function (): void {
    $first = User::query()->create(['name' => 'Ada']);
    $second = User::query()->create(['name' => 'Grace']);

    $keys = [...createPosts($first, 1)->modelKeys(), ...createPosts($second, 1)->modelKeys()];

    $authors = SupportCollection::make([$first, $second])->keyBy('id');

    $collection = Post::query()
        ->whereKey($keys)
        ->orderBy('id')
        ->get()
        ->plug('author', fn (Post $post): ?User => $authors->get($post->user_id));

    expect($collection->first()->getRelation('author'))->toBe($first)
        ->and($collection->last()->getRelation('author'))->toBe($second);
});

it('only plugs relations that are not loaded yet when asked to plug missing', function (): void {
    $author = User::query()->create(['name' => 'Ada']);
    $replacement = User::query()->create(['name' => 'Grace']);

    $collection = Post::query()->whereKey(createPosts($author, 2)->modelKeys())->orderBy('id')->get();
    $collection->first()->setRelation('author', $author);

    $collection->plugMissing('author', $replacement);

    expect($collection->first()->getRelation('author'))->toBe($author)
        ->and($collection->last()->getRelation('author'))->toBe($replacement);
});

it('plugs attributes onto every model in the collection', function (): void {
    $author = User::query()->create(['name' => 'Ada']);

    $collection = Post::query()
        ->whereKey(createPosts($author, 2)->modelKeys())
        ->orderBy('id')
        ->get()
        ->plugAttribute('upper_title', fn (Post $post): string => Str::upper($post->title));

    expect($collection->map(fn (Post $post): mixed => $post->getAttribute('upper_title'))->values()->all())
        ->toBe(['FIRST POST', 'SECOND POST']);
});

it('plugs morph relations from already loaded models', function (): void {
    $post = createPosts(User::query()->create(['name' => 'Ada']), 1)->first();
    $user = User::query()->create(['name' => 'Grace']);

    $postImage = Image::query()->create([
        'imageable_type' => $post->getMorphClass(),
        'imageable_id' => $post->id,
        'url' => 'post.png',
    ]);
    $userImage = Image::query()->create([
        'imageable_type' => $user->getMorphClass(),
        'imageable_id' => $user->id,
        'url' => 'user.png',
    ]);

    $images = Image::query()->orderBy('id')->get();

    $images->plugMorph('imageable', 'imageable_type', 'imageable_id', [
        Post::query()->whereKey($post->id)->get(),
        collect([$user]),
        [$userImage],
    ]);

    expect($images->first()->getRelation('imageable'))->toBeInstanceOf(Post::class)
        ->and($images->first()->getRelation('imageable')->is($post))->toBeTrue()
        ->and($images->last()->getRelation('imageable'))->toBe($user);
});

it('plugs morph relations stored under the raw class name when a morph map is set', function (): void {
    Relation::morphMap(['user' => User::class]);

    $user = User::query()->create(['name' => 'Ada']);
    $other = User::query()->create(['name' => 'Grace']);

    Image::query()->create([
        'imageable_type' => User::class,
        'imageable_id' => $user->id,
        'url' => 'raw-class.png',
    ]);
    Image::query()->create([
        'imageable_type' => 'user',
        'imageable_id' => $other->id,
        'url' => 'aliased.png',
    ]);

    $images = Image::query()->orderBy('id')->get();

    $images->plugMorph('imageable', 'imageable_type', 'imageable_id', $images->map(
        static fn (Image $image): SupportCollection => SupportCollection::make([
            User::query()->find($image->imageable_id),
        ]),
    ));

    expect($images->first()->getRelation('imageable'))->toBeInstanceOf(User::class)
        ->and($images->first()->getRelation('imageable')->is($user))->toBeTrue()
        ->and($images->last()->getRelation('imageable')->is($other))->toBeTrue();
});

it('leaves morph relations untouched when the type or key is unusable', function (): void {
    Image::query()->create([
        'imageable_type' => null,
        'imageable_id' => null,
        'url' => 'empty.png',
    ]);

    $images = Image::query()->get();

    $images->plugMorph('imageable', 'imageable_type', 'imageable_id', [new Post()]);

    expect($images->first()->relationLoaded('imageable'))->toBeFalse();
});

it('prepares appended attributes and calls methods on every model', function (): void {
    $author = User::query()->create(['name' => 'Ada']);
    createPosts($author, 1);

    $collection = Post::query()->select('id', 'user_id', 'title')->get();

    $collection->plugAppend('upper_title', ['author'], ['upperTitle']);

    expect($collection->first()->getRelation('author')->name)->toBe('Ada')
        ->and($collection->first()->getAttribute('upper_title'))->toBe('FIRST POST')
        ->and($collection->first()->getAppends())->toContain('upper_title');
});

it('rejects appends for methods the model does not have', function (): void {
    Post::query()->create(['user_id' => null, 'title' => 'First post']);

    $collection = Post::query()->get();

    expect(fn () => $collection->plugAppend('upper_title', [], ['missingMethod']))
        ->toThrow(InvalidArgumentException::class, 'Method [missingMethod] does not exist on');
});


it('hydrates a cached single relation once and shares it across the collection', function (): void {
    $author = User::query()->create(['name' => 'Ada']);
    $posts = createPosts($author, 2);

    $resolutions = 0;

    $collection = Post::query()->whereKey($posts->modelKeys())->get()->plugCached(
        relation: 'author',
        localKey: 'user_id',
        relatedClass: User::class,
        resolver: static function (array $ids) use (&$resolutions): SupportCollection {
            $resolutions++;

            return User::query()->whereIn('id', $ids)->get()->keyBy('id');
        },
        cacheKey: static fn (mixed $id): string => 'fluent-test:plug-cached:author:'.$id,
        ttl: 60,
    );

    expect($resolutions)->toBe(1)
        ->and($collection->first()->getRelation('author')->name)->toBe('Ada')
        ->and($collection->last()->getRelation('author'))->toBe($collection->first()->getRelation('author'))
        ->and(Cache::get('fluent-test:plug-cached:author:'.$author->id))->toEqual($author->getAttributes());
});

it('reads cached relations from the store without resolving them again', function (): void {
    $author = User::query()->create(['name' => 'Ada']);
    $posts = createPosts($author, 2);

    Cache::put('fluent-test:plug-cached-warm:'.$author->id, $author->getAttributes(), 60);

    $resolutions = 0;

    $collection = Post::query()->whereKey($posts->modelKeys())->get()->plugCached(
        relation: 'author',
        localKey: 'user_id',
        relatedClass: User::class,
        resolver: static function (array $ids) use (&$resolutions): SupportCollection {
            $resolutions++;

            return User::query()->whereIn('id', $ids)->get()->keyBy('id');
        },
        cacheKey: static fn (mixed $id): string => 'fluent-test:plug-cached-warm:'.$id,
        ttl: 60,
    );

    expect($resolutions)->toBe(0)
        ->and($collection->first()->getRelation('author')->getKey())->toBe($author->getKey())
        ->and($collection->first()->getRelation('author')->name)->toBe('Ada');
});

it('leaves keys without a cached record unhydrated', function (): void {
    $author = User::query()->create(['name' => 'Ada']);

    Post::query()->create(['user_id' => $author->id, 'title' => 'First post']);
    Post::query()->create(['user_id' => null, 'title' => 'Second post']);

    $collection = Post::query()->orderBy('id')->get()->plugCached(
        relation: 'author',
        localKey: 'user_id',
        relatedClass: User::class,
        resolver: static fn (array $ids): SupportCollection => User::query()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id'),
        cacheKey: static fn (mixed $id): string => 'fluent-test:plug-cached-missing:author:'.$id,
        ttl: 60,
    );

    expect($collection->first()->getRelation('author'))->toBeInstanceOf(User::class)
        ->and($collection->last()->getRelation('author'))->toBeNull();
});

it('only fills relations that are not loaded yet when plugging cached missing relations', function (): void {
    $author = User::query()->create(['name' => 'Ada']);
    $posts = createPosts($author, 2);

    $preloaded = User::query()->create(['name' => 'Grace']);

    $collection = Post::query()->whereKey($posts->modelKeys())->orderBy('id')->get();
    $collection->first()->setRelation('author', $preloaded);

    $collection->plugCachedMissing(
        relation: 'author',
        localKey: 'user_id',
        relatedClass: User::class,
        resolver: static fn (array $ids): SupportCollection => User::query()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id'),
        cacheKey: static fn (mixed $id): string => 'fluent-test:plug-cached-missing-only:'.$id,
        ttl: 60,
    );

    expect($collection->first()->getRelation('author'))->toBe($preloaded)
        ->and($collection->last()->getRelation('author')->getKey())->toBe($author->getKey());
});

it('hydrates cached collection relations and caches empty results', function (): void {
    $author = User::query()->create(['name' => 'Ada']);
    $posts = createPosts($author, 2);

    createComments($posts->first(), 2);

    $collection = Post::query()->whereKey($posts->modelKeys())->get()->plugCachedCollection(
        relation: 'comments',
        localKey: 'id',
        relatedClass: Comment::class,
        resolver: static fn (array $ids): SupportCollection => Comment::query()
            ->whereIn('post_id', $ids)
            ->get()
            ->groupBy('post_id'),
        cacheKey: static fn (mixed $id): string => 'fluent-test:plug-cached-collection:'.$id,
        ttl: 60,
        collectionClass: CommentCollection::class,
    );

    expect($collection->first()->getRelation('comments'))->toBeInstanceOf(CommentCollection::class)
        ->and($collection->first()->getRelation('comments'))->toHaveCount(2)
        ->and($collection->last()->getRelation('comments'))->toBeInstanceOf(CommentCollection::class)
        ->and($collection->last()->getRelation('comments')->isEmpty())->toBeTrue()
        ->and(Cache::get('fluent-test:plug-cached-collection:'.$posts->last()->id))->toBe([]);
});

it('does not touch the database when there is nothing to hydrate', function (): void {
    User::query()->create(['name' => 'Ada']);

    $collection = Post::query()->whereKey([999999])->get();

    $queries = $this->countQueries();

    $collection->plugCached(
        relation: 'author',
        localKey: 'user_id',
        relatedClass: User::class,
        resolver: static fn (array $ids): SupportCollection => User::query()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id'),
        cacheKey: static fn (mixed $id): string => 'fluent-test:never:'.$id,
        ttl: 60,
    );

    $collection->plugCachedCollection(
        relation: 'comments',
        localKey: 'id',
        relatedClass: Comment::class,
        resolver: static fn (array $ids): SupportCollection => Comment::query()
            ->whereIn('post_id', $ids)
            ->get()
            ->groupBy('post_id'),
        cacheKey: static fn (mixed $id): string => 'fluent-test:never-collection:'.$id,
        ttl: 60,
        collectionClass: CommentCollection::class,
    );

    expect($queries())->toBe(0);
});
