<?php

declare(strict_types=1);

use Webong\Cogent\Resources\ResourcePlan;
use Webong\Cogent\Tests\Fixtures\Events\PostUpdated;
use Webong\Cogent\Tests\Fixtures\Jobs\PlainPostJob;
use Webong\Cogent\Tests\Fixtures\Jobs\PreparedPostJob;
use Webong\Cogent\Tests\Fixtures\Jobs\RestoresUser;
use Webong\Cogent\Tests\Fixtures\Models\Comment;
use Webong\Cogent\Tests\Fixtures\Models\Post;
use Webong\Cogent\Tests\Fixtures\Models\Tag;
use Webong\Cogent\Tests\Fixtures\Models\User;

beforeEach(function (): void {
    ResourcePlan::flush();

    $author = User::query()->create(['name' => 'Ada']);
    Tag::query()->create(['taggable_type' => User::class, 'taggable_id' => $author->id, 'name' => 'math']);
    createComments(createPosts($author)->first(), 2);
});

/**
 * Serialize and restore in a fresh request scope, the way a queue worker would.
 */
function throughQueue(object $event): object
{
    $payload = serialize($event);

    app()->forgetScopedInstances();

    return unserialize($payload);
}

it('restores models with what their resources render', function (): void {
    $event = new PostUpdated(
        post: Post::query()->firstOrFail(),
        editor: User::query()->firstOrFail(),
        comments: Comment::query()->orderBy('id')->get(),
        reason: 'typo',
    );

    $restored = throughQueue($event);

    expect($restored->post->relationLoaded('author'))->toBeTrue()
        ->and($restored->post->getAttributes())->toHaveKey('comments_count', 2)
        ->and($restored->editor->getAttributes())->toHaveKey('posts_count', 1)
        ->and($restored->editor->tags->pluck('name')->all())->toBe(['math'])
        // CommentResource is found through #[UseResource], from Laravel 12.
        ->and($restored->comments->every(fn (Comment $comment): bool => $comment->relationLoaded('post')))->toBe(supportsUseResource());
});

it('broadcasts a restored event without querying again', function (): void {
    $restored = throughQueue(new PostUpdated(
        post: Post::query()->firstOrFail(),
        editor: User::query()->firstOrFail(),
        comments: Comment::query()->orderBy('id')->get(),
        reason: 'typo',
    ));

    $queries = $this->countQueries();

    $payload = $restored->broadcastWith();

    expect($queries())->toBe(0)
        ->and($payload['editor']['tags'])->toBe(['math'])
        ->and($payload['post']['comments_count'])->toBe(2);
});

it('reads relations the model had from the cache when they are cacheable', function (): void {
    $user = User::query()->firstOrFail();
    $user->setRelation('tags', $user->tags()->get());

    $job = new RestoresUser($user);

    // Warm the cache the way an earlier request would have.
    User::query()->get()->loadCached('tags');

    $payload = serialize($job);
    app()->forgetScopedInstances();
    $queries = $this->countQueries();

    $restored = unserialize($payload);

    // Only the user: its tags came from the cache.
    expect($queries())->toBe(1)
        ->and($restored->user->tags->pluck('name')->all())->toBe(['math']);
});

it('restores the relations Laravel would with SerializesModels', function (): void {
    $post = Post::query()->with('comments')->firstOrFail();

    $plain = new PlainPostJob($post);
    $prepared = new PreparedPostJob($post);

    expect(array_keys(throughQueue($prepared)->post->getRelations()))
        ->toBe(array_keys(throughQueue($plain)->post->getRelations()));
});
