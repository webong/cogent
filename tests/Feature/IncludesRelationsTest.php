<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Webong\Fluent\Tests\Fixtures\Collections\ImageCollection;
use Webong\Fluent\Tests\Fixtures\Collections\PostCollection;
use Webong\Fluent\Tests\Fixtures\Models\Image;
use Webong\Fluent\Tests\Fixtures\Models\Post;
use Webong\Fluent\Tests\Fixtures\Models\Tag;
use Webong\Fluent\Tests\Fixtures\Models\User;

it('collects models reachable through already loaded relations', function (): void {
    $author = User::query()->create(['name' => 'Ada']);
    $posts = createPosts($author, 2);

    createComments($posts->first(), 1);
    createComments($posts->last(), 2);

    $collection = Post::query()->whereKey($posts->modelKeys())->get()->load('comments');

    $graph = $collection->include('comments');

    expect($graph)->toBeInstanceOf(PostCollection::class)
        ->and($graph)->toHaveCount(5)
        ->and($graph->filter(fn ($model) => $model instanceof Post))->toHaveCount(2)
        ->and($graph->filter(fn ($model) => ! $model instanceof Post))->toHaveCount(3)
        ->and($graph->filter(fn ($model) => $model instanceof Post)->first())->toBe($collection->first());
});

it('keeps a single instance per model while walking a graph', function (): void {
    $author = User::query()->create(['name' => 'Ada']);
    $posts = createPosts($author, 1);

    createComments($posts->first(), 1);

    $collection = Post::query()->whereKey($posts->modelKeys())->get()->load('comments.post');

    $graph = $collection->include('comments');

    $objectIds = $graph->map(static fn ($model): int => spl_object_id($model))->all();

    expect($graph)->toHaveCount(2)
        ->and(array_unique($objectIds))->toHaveCount(2);
});

it('returns a typed empty collection when nothing is loaded', function (): void {
    $collection = new PostCollection;

    expect($collection->include('comments'))->toBeInstanceOf(PostCollection::class)
        ->and($collection->include('comments'))->toBeEmpty();
});

it('loads nested relation paths while sharing duplicate instances', function (): void {
    $author = User::query()->create(['name' => 'Ada']);
    $posts = createPosts($author, 2);

    createComments($posts->first(), 2);

    $collection = Post::query()->whereKey($posts->modelKeys())->orderBy('id')->get();

    $queries = $this->countQueries();

    expect($collection->includeMissing('comments.post.author'))->toBe($collection)
        ->and($queries())->toBe(3)
        ->and($collection->first()->relationLoaded('comments'))->toBeTrue();

    $comments = $collection->first()->getRelation('comments');

    expect($comments->first()->relationLoaded('post'))->toBeTrue()
        ->and($comments->first()->getRelation('post')->relationLoaded('author'))->toBeTrue()
        ->and($comments->first()->getRelation('post'))->toBe($collection->first())
        ->and($comments->first()->getRelation('post')->getRelation('author'))
        ->toBe($comments->last()->getRelation('post')->getRelation('author'));
});

it('loads relation paths for every model class in a mixed collection', function (): void {
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

    $collection = new PostCollection([$author, $post]);

    $collection->includeMissing('tags');

    expect($author->relationLoaded('tags'))->toBeTrue()
        ->and($post->relationLoaded('tags'))->toBeTrue()
        ->and($author->getRelation('tags')->first()->name)->toBe('collections')
        ->and($post->getRelation('tags')->first()->name)->toBe('eloquent');
});

it('does not query for an empty collection when including missing relations', function (): void {
    $collection = new PostCollection;

    $queries = $this->countQueries();

    expect($collection->includeMissing('comments'))->toBe($collection)
        ->and($queries())->toBe(0);
});

it('loads nested relations only for the requested morph classes', function (): void {
    $author = User::query()->create(['name' => 'Ada']);
    $post = createPosts($author, 1)->first();

    Image::query()->create([
        'imageable_type' => $post->getMorphClass(),
        'imageable_id' => $post->id,
        'url' => 'post.png',
    ]);
    Image::query()->create([
        'imageable_type' => $author->getMorphClass(),
        'imageable_id' => $author->id,
        'url' => 'user.png',
    ]);

    $images = Image::query()->orderBy('id')->get();

    expect($images)->toBeInstanceOf(ImageCollection::class)
        ->and($images->includeMissingMorph('imageable', [Post::class => ['author']]))->toBe($images);

    expect($images->first()->getRelation('imageable'))->toBeInstanceOf(Post::class)
        ->and($images->first()->getRelation('imageable')->relationLoaded('author'))->toBeTrue()
        ->and($images->last()->getRelation('imageable'))->toBeInstanceOf(User::class)
        ->and($images->last()->getRelation('imageable')->relationLoaded('author'))->toBeFalse();
});

it('ignores morph classes without matching related models', function (): void {
    $author = User::query()->create(['name' => 'Ada']);
    $post = createPosts($author, 1)->first();

    Image::query()->create([
        'imageable_type' => $post->getMorphClass(),
        'imageable_id' => $post->id,
        'url' => 'post.png',
    ]);

    $images = Image::query()->get();

    $images->includeMissingMorph(['imageable'], [Tag::class => ['taggable']]);

    expect($images->first()->getRelation('imageable'))->toBeInstanceOf(Post::class)
        ->and($images->first()->getRelation('imageable')->relationLoaded('taggable'))->toBeFalse();
});

it('includes support collections of related models', function (): void {
    $author = User::query()->create(['name' => 'Ada']);
    $post = createPosts($author, 1)->first();

    $collection = Post::query()->whereKey([$post->id])->get();
    $collection->first()->setRelation('comments', collect(createComments($post, 2)->all()));

    $graph = $collection->include('comments');

    expect($graph)->toHaveCount(3)
        ->and($graph->last())->toBeInstanceOf(\Webong\Fluent\Tests\Fixtures\Models\Comment::class);
});

it('ignores relations that are not loaded at all', function (): void {
    $author = User::query()->create(['name' => 'Ada']);
    $posts = createPosts($author, 1);

    $collection = Post::query()->whereKey($posts->modelKeys())->get();

    expect($collection->include('comments'))->toBeInstanceOf(EloquentCollection::class)
        ->and($collection->include('comments'))->toHaveCount(1);
});
