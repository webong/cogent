<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection;
use Webong\Cogent\Attributes\WithResponse;
use Webong\Cogent\Concerns\BroadcastsResources;
use Webong\Cogent\Tests\Fixtures\Events\ResponsePostUpdated;
use Webong\Cogent\Tests\Fixtures\Events\ResponsePostResourceEvent;
use Webong\Cogent\Tests\Fixtures\Events\ResponsePostWrapEvent;
use Webong\Cogent\Tests\Fixtures\Models\ResponsePost;
use Webong\Cogent\Tests\Fixtures\Models\User;

function createResponsePost(array $attributes = []): ResponsePost
{
    $author = User::query()->create(['name' => 'Ada']);

    return ResponsePost::query()->create(array_merge([
        'user_id' => $author->id,
        'title' => 'First post',
        'secret' => 'top-secret',
    ], $attributes));
}

beforeEach(function (): void {
    createResponsePost();
});

it('applies appends to a model via #[WithResponse] before broadcast', function (): void {
    $post = ResponsePost::query()->firstOrFail();

    $event = new class ($post) {
        use BroadcastsResources;

        public function __construct(
            #[WithResponse(appends: ['upper_title'])]
            public ResponsePost $post,
        ) {
        }
    };

    $event->broadcastWith();

    expect($event->post->getAppends())->toContain('upper_title');
});

it('hides attributes from a model via #[WithResponse] before broadcast', function (): void {
    $post = ResponsePost::query()->firstOrFail();

    $event = new class ($post) {
        use BroadcastsResources;

        public function __construct(
            #[WithResponse(hidden: ['secret'])]
            public ResponsePost $post,
        ) {
        }
    };

    $event->broadcastWith();

    expect($event->post->getHidden())->toContain('secret');
});

it('applies both appends and hidden from #[WithResponse]', function (): void {
    $post = ResponsePost::query()->firstOrFail();

    $event = new class ($post) {
        use BroadcastsResources;

        public function __construct(
            #[WithResponse(appends: ['upper_title'], hidden: ['secret'])]
            public ResponsePost $post,
        ) {
        }
    };

    $event->broadcastWith();

    expect($event->post->getAppends())->toContain('upper_title');
    expect($event->post->getHidden())->toContain('secret');
});

it('sets visible attributes on a model via #[WithResponse] before broadcast', function (): void {
    $post = ResponsePost::query()->firstOrFail();

    $event = new class ($post) {
        use BroadcastsResources;

        public function __construct(
            #[WithResponse(visible: ['id', 'title'])]
            public ResponsePost $post,
        ) {
        }
    };

    $event->broadcastWith();

    expect($event->post->getVisible())->toBe(['id', 'title']);
});

it('wraps the broadcast response via #[WithResponse]', function (): void {
    $post = ResponsePost::query()->firstOrFail();

    $event = new ResponsePostWrapEvent($post);

    $payload = $event->broadcastWith();

    expect($payload['post'])->toHaveKey('data');
    expect($payload['post']['data'])->toHaveKey('id');
    expect($payload['post']['data'])->toHaveKey('title');
});

it('uses the resource specified by #[WithResponse] for broadcast', function (): void {
    $post = ResponsePost::query()->firstOrFail();

    $event = new ResponsePostResourceEvent($post);

    $payload = $event->broadcastWith();

    expect($payload['post'])->toHaveKey('id');
    expect($payload['post'])->toHaveKey('title');
    expect($payload['post'])->not->toHaveKey('secret');
});

it('applies #[WithResponse] appends/hidden when restoring from serialization', function (): void {
    $post = ResponsePost::query()->firstOrFail();

    $event = new ResponsePostUpdated($post, $post, new Collection(), 'typo');

    $payload = serialize($event);
    app()->forgetScopedInstances();
    $restored = unserialize($payload);

    expect($restored->post->getAppends())->toContain('upper_title');
    expect($restored->post->getHidden())->toContain('secret');
});

it('applies #[WithResponse] visible when restoring from serialization', function (): void {
    $post = ResponsePost::query()->firstOrFail();

    $event = new ResponsePostUpdated($post, $post, new Collection(), 'typo');

    $payload = serialize($event);
    app()->forgetScopedInstances();
    $restored = unserialize($payload);

    expect($restored->editor->getVisible())->toBe(['id', 'title']);
});
