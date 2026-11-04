<?php

declare(strict_types=1);

use Illuminate\Broadcasting\BroadcastEvent;
use Webong\Cogent\Resources\ResourcePlan;
use Webong\Cogent\Tests\Fixtures\Events\PostSnapshotted;
use Webong\Cogent\Tests\Fixtures\Models\Post;
use Webong\Cogent\Tests\Fixtures\Models\User;

beforeEach(function (): void {
    ResourcePlan::flush();

    createComments(createPosts(User::query()->create(['name' => 'Ada']))->first(), 2);
});

it('renders the payload when queued and broadcasts it without a query', function (): void {
    $job = serialize(new BroadcastEvent(new PostSnapshotted(Post::query()->firstOrFail(), 'typo')));

    // The worker, after the post changed.
    Post::query()->whereKey(1)->update(['title' => 'Changed later']);
    app()->forgetScopedInstances();
    $queries = $this->countQueries();

    $event = unserialize($job)->event;

    expect($event->broadcastWith())->toMatchArray([
        'post' => [
            'id' => 1,
            'title' => 'First post',
            'author' => ['id' => 1, 'name' => 'Ada', 'tags' => [], 'posts_count' => 1],
            'comments_count' => 2,
        ],
        'reason' => 'typo',
    ])
        ->and(array_map(strval(...), $event->broadcastOn()))->toBe(['private-posts.1'])
        ->and($event->post->relationLoaded('author'))->toBeTrue()
        ->and($queries())->toBe(0);
});

it('renders the payload when it is broadcast right away', function (): void {
    $event = new PostSnapshotted(Post::query()->firstOrFail(), 'typo');

    expect($event->broadcastWith()['post']['comments_count'])->toBe(2);
});
