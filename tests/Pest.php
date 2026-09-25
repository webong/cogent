<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection as SupportCollection;
use Webong\Fluent\Tests\Fixtures\Collections\PostCollection;
use Webong\Fluent\Tests\Fixtures\Models\Comment;
use Webong\Fluent\Tests\Fixtures\Models\Post;
use Webong\Fluent\Tests\Fixtures\Models\User;
use Webong\Fluent\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

afterEach(function (): void {
    Relation::morphMap([], false);
});

/**
 * @return PostCollection<int, Post>
 */
function createPosts(User $author, int $count = 1): PostCollection
{
    $titles = [1 => 'First post', 2 => 'Second post', 3 => 'Third post', 4 => 'Fourth post'];

    $posts = new PostCollection;

    foreach (array_slice($titles, 0, $count) as $title) {
        $posts->push(Post::query()->create([
            'user_id' => $author->id,
            'title' => $title,
        ]));
    }

    return $posts;
}

/**
 * @return SupportCollection<int, Comment>
 */
function createComments(Post $post, int $count = 1): SupportCollection
{
    $comments = new SupportCollection;

    for ($index = 1; $index <= $count; $index++) {
        $comments->push(Comment::query()->create([
            'post_id' => $post->id,
            'body' => 'Comment #'.$index,
        ]));
    }

    return $comments;
}
