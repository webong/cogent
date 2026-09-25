<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Webong\Fluent\Support\BatchCountResult;
use Webong\Fluent\Support\EloquentBatchCounter;
use Webong\Fluent\Tests\Fixtures\Models\Comment;
use Webong\Fluent\Tests\Fixtures\Models\Post;
use Webong\Fluent\Tests\Fixtures\Models\User;

it('returns an empty result without querying when there are no parent ids', function (): void {
    $queries = $this->countQueries();

    $result = app(EloquentBatchCounter::class)->count(
        query: Comment::query(),
        groupBy: 'post_id',
        parentIds: [],
    );

    expect($result)->toBeInstanceOf(BatchCountResult::class)
        ->and($queries())->toBe(0)
        ->and($result->countFor(1))->toBe(0)
        ->and($result->isTruncated(1))->toBeFalse();
});

it('resolves counts grouped by the requested column', function (): void {
    $author = User::query()->create(['name' => 'Ada']);
    $posts = createPosts($author, 2);

    createComments($posts->first(), 3);

    $result = app(EloquentBatchCounter::class)->count(
        query: Comment::query(),
        groupBy: (new Comment)->qualifyColumn('post_id'),
        parentIds: $posts->modelKeys(),
        cap: null,
    );

    expect($result->countFor($posts->first()->id))->toBe(3)
        ->and($result->countFor($posts->last()->id))->toBe(0)
        ->and($result->counts()->all())->toHaveCount(1);
});

it('accepts a constraint closure that returns nothing', function (): void {
    $author = User::query()->create(['name' => 'Ada']);
    $posts = createPosts($author, 1);

    createComments($posts->first(), 2);

    $result = app(EloquentBatchCounter::class)->count(
        query: Comment::query(),
        groupBy: (new Comment)->qualifyColumn('post_id'),
        parentIds: $posts->modelKeys(),
        cap: null,
        constraints: static function (Builder $query): void {
            $query->where('body', 'Nothing like this');
        },
    );

    expect($result->countFor($posts->first()->id))->toBe(0)
        ->and($result->counts()->all())->toBeEmpty();
});

it('never mutates the query it is given', function (): void {
    $author = User::query()->create(['name' => 'Ada']);
    $posts = createPosts($author, 1);

    $query = Post::query();

    app(EloquentBatchCounter::class)->count(
        query: $query,
        groupBy: (new Post)->qualifyColumn('id'),
        parentIds: $posts->modelKeys(),
        cap: null,
    );

    expect($query->toBase()->wheres)->toBeEmpty()
        ->and($query->toBase()->groups)->toBeEmpty();
});

it('refuses a negative cap', function (): void {
    expect(fn () => app(EloquentBatchCounter::class)->count(
        query: Comment::query(),
        groupBy: 'post_id',
        parentIds: [1],
        cap: -1,
    ))->toThrow(InvalidArgumentException::class, '$countCap must be null or a non-negative integer.');
});
