<?php

declare(strict_types=1);

use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Support\Facades\Broadcast;
use Webong\Cogent\Resources\ResourcePlan;
use Webong\Cogent\Tests\Fixtures\Broadcasting\CapturingBroadcaster;
use Webong\Cogent\Tests\Fixtures\Events\PostUpdated;
use Webong\Cogent\Tests\Fixtures\Models\Comment;
use Webong\Cogent\Tests\Fixtures\Models\Post;
use Webong\Cogent\Tests\Fixtures\Models\User;

beforeEach(function (): void {
    ResourcePlan::flush();

    $author = User::query()->create(['name' => 'Ada']);
    createComments(createPosts($author)->first(), 2);
});

function postUpdated(): PostUpdated
{
    return new PostUpdated(
        post: Post::query()->firstOrFail(),
        editor: User::query()->firstOrFail(),
        comments: Comment::query()->orderBy('id')->get(),
        reason: 'typo',
    );
}

it('renders models and collections through their resources', function (): void {
    $event = postUpdated();
    $queries = $this->countQueries();

    $payload = $event->broadcastWith();

    // PostResource: author, its tags, post counts and comment counts. The editor is
    // another instance, so UserResource counts its posts again but reads its tags
    // from the cache. CommentResource loads every comment's post.
    expect($queries())->toBe(6)
        ->and($payload)->toBe([
            'post' => [
                'id' => 1,
                'title' => 'First post',
                'author' => ['id' => 1, 'name' => 'Ada', 'tags' => [], 'posts_count' => 1],
                'comments_count' => 2,
            ],
            'editor' => ['id' => 1, 'name' => 'Ada', 'tags' => [], 'posts_count' => 1],
            'comments' => [
                ['id' => 1, 'body' => 'Comment #1', 'post_title' => 'First post'],
                ['id' => 2, 'body' => 'Comment #2', 'post_title' => 'First post'],
            ],
            'reason' => 'typo',
        ]);
});

it('sends the resource payload through the broadcaster', function (): void {
    $broadcaster = new CapturingBroadcaster;

    Broadcast::extend('capture', fn (): CapturingBroadcaster => $broadcaster);
    config(['broadcasting.default' => 'capture', 'broadcasting.connections.capture' => ['driver' => 'capture']]);

    (new BroadcastEvent(postUpdated()))->handle(app(BroadcastFactory::class));

    expect($broadcaster->broadcasts)->toHaveCount(1)
        ->and($broadcaster->broadcasts[0]['channels'])->toBe(['private-posts.1'])
        ->and($broadcaster->broadcasts[0]['event'])->toBe(PostUpdated::class)
        ->and($broadcaster->broadcasts[0]['payload']['post']['comments_count'])->toBe(2)
        ->and($broadcaster->broadcasts[0]['payload'])->toHaveKey('socket');
});

it('falls back to toArray() for models without a resource', function (): void {
    $event = new class (User::query()->firstOrFail()) {
        use Webong\Cogent\Concerns\BroadcastsResources;

        public function __construct(public User $user)
        {
        }
    };

    expect($event->broadcastWith())->toBe(['user' => ['id' => 1, 'name' => 'Ada']]);
});

it('broadcasts a model event as the model resource', function (): void {
    $post = Webong\Cogent\Tests\Fixtures\Models\BroadcastingPost::query()->firstOrFail();

    expect($post->newBroadcastableModelEvent('updated')->broadcastWith())->toBe([
        'id' => 1,
        'title' => 'First post',
        'author' => ['id' => 1, 'name' => 'Ada', 'tags' => [], 'posts_count' => 1],
        'comments_count' => 2,
    ]);
});

it('keeps Laravel\'s model event payload for models without a resource', function (): void {
    $user = new class extends User {
        use Webong\Cogent\Concerns\BroadcastsAsResource;

        protected $table = 'users';
    };

    expect($user->broadcastWith('created'))->toBeNull();
});

it('throws when a payload is over the broadcast limit', function (): void {
    Webong\Cogent\Cogent::limitBroadcasts(100);

    try {
        postUpdated()->broadcastWith();
    } finally {
        Webong\Cogent\Cogent::flush();
    }
})->throws(Webong\Cogent\Exceptions\BroadcastTooLargeException::class, 'over the limit of 100');

it('reports oversized model broadcasts through a handler and still sends them', function (): void {
    $reported = [];

    Webong\Cogent\Cogent::limitBroadcasts(10);
    Webong\Cogent\Cogent::handleOversizedBroadcastsUsing(function (object $event, int $bytes, int $limit) use (&$reported): void {
        $reported[] = [$event::class, $bytes > $limit];
    });

    try {
        $payload = Webong\Cogent\Tests\Fixtures\Models\BroadcastingPost::query()->firstOrFail()->broadcastWith('updated');
    } finally {
        Webong\Cogent\Cogent::flush();
    }

    expect($payload)->toHaveKey('title')
        ->and($reported)->toBe([[Webong\Cogent\Tests\Fixtures\Models\BroadcastingPost::class, true]]);
});

it('lets payloads under the limit through', function (): void {
    Webong\Cogent\Cogent::limitBroadcasts();

    try {
        expect(postUpdated()->broadcastWith())->toHaveKey('post');
    } finally {
        Webong\Cogent\Cogent::flush();
    }
});
