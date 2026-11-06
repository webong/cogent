<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Broadcast;
use Webong\Cogent\Cogent;
use Webong\Cogent\Resources\ResourcePlan;
use Webong\Cogent\Tests\Fixtures\Broadcasting\CapturingBroadcaster;
use Webong\Cogent\Tests\Fixtures\Models\BroadcastingPost;
use Webong\Cogent\Tests\Fixtures\Models\User;

beforeEach(function (): void {
    ResourcePlan::flush();

    new CapturingBroadcaster;
    Broadcast::extend('capture', fn (): CapturingBroadcaster => CapturingBroadcaster::$last);
    config(['broadcasting.default' => 'capture', 'broadcasting.connections.capture' => ['driver' => 'capture']]);

    BroadcastingPost::withoutBroadcasting(function (): void {
        foreach (createPosts(User::query()->create(['name' => 'Ada']), 3) as $index => $post) {
            createComments($post, $index + 1);
        }
    });
});

/**
 * @return list<BroadcastingPost>
 */
function broadcastingPosts(): array
{
    return BroadcastingPost::query()->orderBy('id')->get()->all();
}

it('prepares every model once and sends a snapshot for each event', function (): void {
    $broadcaster = CapturingBroadcaster::$last;
    $posts = broadcastingPosts();
    $queries = $this->countQueries();

    Cogent::batchBroadcasts(function () use ($posts): void {
        foreach ($posts as $post) {
            $post->update(['title' => $post->title.'!']);
        }

        expect(CapturingBroadcaster::$last->broadcasts)->toBe([]);
    });

    // Three updates, then one pass: author, tags, post counts and comment counts.
    expect($queries())->toBe(7)
        ->and(CapturingBroadcaster::$last->broadcasts)->toHaveCount(3)
        ->and(CapturingBroadcaster::$last->broadcasts[2]['event'])->toBe('BroadcastingPostUpdated')
        ->and(CapturingBroadcaster::$last->broadcasts[2]['channels'])->toBe(['private-posts'])
        ->and(CapturingBroadcaster::$last->broadcasts[2]['payload'])->toMatchArray([
            'title' => 'Third post!',
            'comments_count' => 3,
            'author' => ['id' => 1, 'name' => 'Ada', 'tags' => [], 'posts_count' => 3],
        ]);
});

it('sends each event on its own outside a batch', function (): void {
    $broadcaster = CapturingBroadcaster::$last;
    $posts = broadcastingPosts();
    $queries = $this->countQueries();

    foreach ($posts as $post) {
        $post->update(['title' => $post->title.'!']);
    }

    expect($broadcaster->broadcasts)->toHaveCount(3)
        ->and($queries())->toBeGreaterThan(7);
});

it('sends nothing when the callback throws', function (): void {
    $broadcaster = CapturingBroadcaster::$last;
    $posts = broadcastingPosts();

    try {
        Cogent::batchBroadcasts(function () use ($posts): void {
            $posts[0]->update(['title' => 'Rolled back']);

            throw new RuntimeException('Failed');
        });
    } catch (RuntimeException) {
        //
    }

    expect($broadcaster->broadcasts)->toBe([]);
});

it('sends once the outermost batch ends', function (): void {
    $broadcaster = CapturingBroadcaster::$last;
    $posts = broadcastingPosts();

    $result = Cogent::batchBroadcasts(function () use ($posts): string {
        Cogent::batchBroadcasts(fn () => $posts[0]->update(['title' => 'Inner']));

        expect(CapturingBroadcaster::$last->broadcasts)->toBe([]);

        return 'done';
    });

    expect($result)->toBe('done')
        ->and(CapturingBroadcaster::$last->broadcasts)->toHaveCount(1);
});
