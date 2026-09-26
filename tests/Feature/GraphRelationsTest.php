<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Webong\Fluent\Tests\Fixtures\Models\Comment;
use Webong\Fluent\Tests\Fixtures\Models\Post;
use Webong\Fluent\Tests\Fixtures\Models\User;

it('returns the models reachable through an already loaded relation path', function (): void {
    $author = User::query()->create(['name' => 'Ada']);
    $posts = createPosts($author, 2);

    createComments($posts->first(), 1);
    createComments($posts->last(), 2);

    $collection = Post::query()
        ->whereKey($posts->modelKeys())
        ->get()
        ->load('comments.post');

    expect($collection->related('comments'))->toBeInstanceOf(EloquentCollection::class)
        ->and($collection->related('comments'))->toHaveCount(3)
        ->and($collection->related('comments.post'))->toHaveCount(3)
        ->and($collection->related('missing')->isEmpty())->toBeTrue();
});

it('shares one instance across duplicate collection relations', function (): void {
    $author = User::query()->create(['name' => 'Ada']);
    $post = createPosts($author, 1)->first();
    $comment = createComments($post, 1)->first();

    $collection = Post::query()->whereKey([$post->id])->get();

    $collection->first()->setRelation('comments', EloquentCollection::make([
        $comment,
        Comment::query()->findOrFail($comment->id),
    ]));

    $collection->shareRelation('comments');

    $hydrated = $collection->first()->getRelation('comments');

    expect($hydrated)->toHaveCount(2)
        ->and($hydrated->first())->toBe($hydrated->last());
});

it('shares one instance across duplicate single relations', function (): void {
    $author = User::query()->create(['name' => 'Ada']);
    $posts = createPosts($author, 2);

    $collection = Post::query()->whereKey($posts->modelKeys())->orderBy('id')->get();

    $collection->first()->setRelation('author', $author);
    $collection->last()->setRelation('author', User::query()->findOrFail($author->id));

    $collection->shareRelation('author');

    expect($collection->first()->getRelation('author'))->toBe($collection->last()->getRelation('author'));
});

it('leaves empty relations untouched when sharing instances', function (): void {
    $author = User::query()->create(['name' => 'Ada']);
    $posts = createPosts($author, 1);

    $collection = Post::query()->whereKey($posts->modelKeys())->get();

    $collection->first()->setRelation('comments', EloquentCollection::make());

    $empty = $collection->first()->getRelation('comments');

    $collection->shareRelation('comments');

    expect($collection->first()->getRelation('comments'))->toBe($empty);
});
