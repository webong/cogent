<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Webong\Fluent\Support\BatchCountResult;
use Webong\Fluent\Tests\Fixtures\Collections\PostCollection;
use Webong\Fluent\Tests\Fixtures\Models\Comment;
use Webong\Fluent\Tests\Fixtures\Models\Post;
use Webong\Fluent\Tests\Fixtures\Models\Tag;
use Webong\Fluent\Tests\Fixtures\Models\User;

it('batch loads an aggregate count onto every model with one grouped query', function (): void {
    $author = User::query()->create(['name' => 'Ada']);
    $posts = createPosts($author, 3);

    createComments($posts->get(0), 2);
    createComments($posts->get(1), 0);
    createComments($posts->get(2), 5);

    $collection = Post::query()->whereKey($posts->modelKeys())->orderBy('id')->get();

    $queries = $this->countQueries();

    $result = $collection->loadAggregateCounts(
        attribute: 'comments_count',
        query: Comment::query(),
        groupBy: 'post_id',
        cap: null,
    );

    expect($result)->toBeInstanceOf(BatchCountResult::class)
        ->and($queries())->toBe(1)
        ->and($collection->map(fn (Post $post): mixed => $post->getAttribute('comments_count'))->values()->all())
        ->toBe([2, 0, 5])
        ->and($result->countFor($posts->get(0)->id))->toBe(2)
        ->and($result->countFor($posts->get(1)->id))->toBe(0)
        ->and($result->countFor(999999))->toBe(0);
});

it('caps the count and records which attributes were truncated', function (): void {
    $author = User::query()->create(['name' => 'Ada']);
    $posts = createPosts($author, 2);

    createComments($posts->get(0), 5);
    createComments($posts->get(1), 1);

    $collection = Post::query()->whereKey($posts->modelKeys())->orderBy('id')->get();

    $result = $collection->loadAggregateCounts(
        attribute: 'comments_count',
        query: Comment::query(),
        groupBy: 'post_id',
        cap: 2,
        truncatedAttribute: 'counts_truncated',
    );

    expect($collection->first()->getAttribute('comments_count'))->toBe(2)
        ->and($collection->last()->getAttribute('comments_count'))->toBe(1)
        ->and($collection->first()->getAttribute('counts_truncated'))->toBe(['comments_count' => true])
        ->and($collection->last()->getAttribute('counts_truncated'))->toBe(['comments_count' => false])
        ->and($result->isTruncated($posts->get(0)->id))->toBeTrue()
        ->and($result->isTruncated($posts->get(1)->id))->toBeFalse();
});

it('merges truncation flags for counts loaded on top of each other', function (): void {
    $author = User::query()->create(['name' => 'Ada']);
    $posts = createPosts($author, 1);

    createComments($posts->first(), 5);

    $collection = Post::query()->whereKey($posts->modelKeys())->get();

    $collection->loadAggregateCounts('comments_count', Comment::query(), 'post_id', 1, null, 'counts_truncated');
    $collection->loadAggregateCounts('posts_count', Post::query(), 'user_id', 10, null, 'counts_truncated');

    expect($collection->first()->getAttribute('counts_truncated'))->toBe([
        'comments_count' => true,
        'posts_count' => false,
    ]);
});

it('applies constraints to the grouped count query', function (): void {
    $author = User::query()->create(['name' => 'Ada']);
    $posts = createPosts($author, 1);

    createComments($posts->first(), 3);

    $collection = Post::query()->whereKey($posts->modelKeys())->get();

    $collection->loadAggregateCounts(
        attribute: 'first_comments_count',
        query: Comment::query(),
        groupBy: 'post_id',
        cap: null,
        constraints: static fn (Builder $query): Builder => $query->where('body', 'Comment #1'),
    );

    expect($collection->first()->getAttribute('first_comments_count'))->toBe(1);
});

it('zeroes counts for models without a key and skips the query', function (): void {
    $collection = new PostCollection([new Post(['title' => 'Unsaved post'])]);

    $queries = $this->countQueries();

    $result = $collection->loadAggregateCounts(
        attribute: 'comments_count',
        query: Comment::query(),
        groupBy: 'post_id',
        cap: 2,
        truncatedAttribute: 'counts_truncated',
    );

    expect($queries())->toBe(0)
        ->and($collection->first()->getAttribute('comments_count'))->toBe(0)
        ->and($collection->first()->getAttribute('counts_truncated'))->toBe(['comments_count' => false])
        ->and($result->counts())->toBeEmpty()
        ->and($result->truncated())->toBeEmpty();
});

it('does not query for an empty collection when batching relation counts', function (): void {
    $collection = new PostCollection;

    $queries = $this->countQueries();

    $result = $collection->loadAggregateCounts('comments_count', Comment::query(), 'post_id');

    expect($queries())->toBe(0)
        ->and($result->counts())->toBeEmpty();
});

it('refuses a negative cap', function (): void {
    $author = User::query()->create(['name' => 'Ada']);
    $posts = createPosts($author, 1);

    $collection = Post::query()->whereKey($posts->modelKeys())->get();

    expect(fn () => $collection->loadAggregateCounts('comments_count', Comment::query(), 'post_id', -1))
        ->toThrow(InvalidArgumentException::class, '$countCap must be null or a non-negative integer.');
});

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
