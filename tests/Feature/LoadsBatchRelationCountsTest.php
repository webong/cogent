<?php

declare(strict_types=1);

use Webong\Fluent\Tests\Fixtures\Collections\PostCollection;
use Webong\Fluent\Tests\Fixtures\Models\Post;
use Webong\Fluent\Tests\Fixtures\Models\User;
use Webong\Fluent\Tests\Fixtures\Models\Tag;

it('batch loads a relation count with a single grouped query', function (): void {
    $author = User::query()->create(['name' => 'Ada']);
    $posts = createPosts($author, 3);

    createComments($posts->get(0), 2);
    createComments($posts->get(1), 0);
    createComments($posts->get(2), 1);

    $collection = Post::query()->whereKey($posts->modelKeys())->orderBy('id')->get();

    $queries = $this->countQueries();

    expect($collection->loadCounts('comments'))->toBe($collection)
        ->and($queries())->toBe(1)
        ->and($collection->map(fn (Post $post): mixed => $post->getAttribute('comments_count'))->values()->all())
        ->toBe([2, 0, 1]);
});

it('batch loads counts for morph one or many relations', function (): void {
    $author = User::query()->create(['name' => 'Ada']);
    $posts = createPosts($author, 2);

    Tag::query()->create([
        'taggable_type' => $posts->first()->getMorphClass(),
        'taggable_id' => $posts->first()->id,
        'name' => 'eloquent',
    ]);

    $collection = Post::query()->whereKey($posts->modelKeys())->orderBy('id')->get();

    $collection->loadCounts('tags');

    expect($collection->first()->getAttribute('tags_count'))->toBe(1)
        ->and($collection->last()->getAttribute('tags_count'))->toBe(0);
});

it('refuses relations that are not has-one-or-many style', function (): void {
    $author = User::query()->create(['name' => 'Ada']);
    $posts = createPosts($author, 1);

    $collection = Post::query()->whereKey($posts->modelKeys())->get();

    expect(fn () => $collection->loadCounts('author'))
        ->toThrow(InvalidArgumentException::class, 'Relationship [author] must be a has-one-or-many style relation.');
});

it('does not query for an empty collection', function (): void {
    $collection = new PostCollection;

    $queries = $this->countQueries();

    expect($collection->loadCounts('comments'))->toBe($collection)
        ->and($queries())->toBe(0);
});
