<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Webong\Cogent\Resources\ResourcePlan;
use Webong\Cogent\Tests\Fixtures\Models\Comment;
use Webong\Cogent\Tests\Fixtures\Models\Post;
use Webong\Cogent\Tests\Fixtures\Models\User;
use Webong\Cogent\Tests\Fixtures\Resources\IncludablePostResource;
use Webong\Cogent\Tests\Fixtures\Resources\RememberedPostResource;

beforeEach(function (): void {
    ResourcePlan::flush();

    $ada = User::query()->create(['name' => 'Ada']);
    $grace = User::query()->create(['name' => 'Grace']);

    createComments(createPosts($ada, 2)->first(), 2);
    createComments(Post::query()->create(['user_id' => $grace->id, 'title' => 'Elsewhere']), 1);
});

/**
 * Render Ada's posts in a fresh request, the way the next request would.
 *
 * @param  array<string, mixed>  $query
 * @return array{data: list<array<string, mixed>>, queries: int}
 */
function renderAgain(array $query = [], ?Closure $resource = null): array
{
    app()->forgetScopedInstances();

    $request = Request::create('/posts', 'GET', $query);
    app()->instance('request', $request);

    $posts = Post::query()->where('user_id', 1)->orderBy('id')->get();
    $queries = test()->countQueries();

    $collection = $resource === null ? RememberedPostResource::collection($posts) : $resource($posts);
    $data = $collection->response($request)->getData(true)['data'];

    return ['data' => $data, 'queries' => $queries()];
}

it('renders a remembered collection without a query on the next request', function (): void {
    $first = renderAgain();
    $second = renderAgain();

    // author, the author's tags, post counts and comment counts.
    expect($first['queries'])->toBe(4)
        ->and($second['queries'])->toBe(0)
        ->and($second['data'])->toBe($first['data'])
        ->and($second['data'][0])->toMatchArray(['title' => 'First post', 'comments_count' => 2]);
});

it('forgets the entry when a rendered model is saved', function (): void {
    renderAgain();

    Post::query()->findOrFail(1)->update(['title' => 'Edited']);

    expect(renderAgain()['data'][0]['title'])->toBe('Edited');
});

it('forgets the entry when a related model it rendered is saved', function (): void {
    renderAgain();

    User::query()->findOrFail(1)->update(['name' => 'Ada Lovelace']);

    expect(renderAgain()['data'][0]['author']['name'])->toBe('Ada Lovelace');
});

it('forgets the entry when a row a count read is created or deleted', function (): void {
    renderAgain();

    Comment::query()->create(['post_id' => 1, 'body' => 'Late']);

    expect(renderAgain()['data'][0]['comments_count'])->toBe(3);

    Comment::query()->where('post_id', 1)->firstOrFail()->delete();

    expect(renderAgain()['data'][0]['comments_count'])->toBe(2);
});

it('forgets the entry when a nested count changes', function (): void {
    renderAgain();

    Post::query()->create(['user_id' => 2, 'title' => 'Not by Ada']);

    expect(renderAgain()['queries'])->toBe(0);

    Post::query()->create(['user_id' => 1, 'title' => 'By Ada']);

    // UserResource counts Ada's posts: the new one changes them.
    expect(renderAgain()['data'][0]['author']['posts_count'])->toBe(3);
});

it('keeps the entry when an unrelated row changes', function (): void {
    renderAgain();

    Comment::query()->create(['post_id' => 3, 'body' => 'On Grace\'s post']);
    User::query()->findOrFail(2)->update(['name' => 'Grace Hopper']);

    expect(renderAgain()['queries'])->toBe(0);
});

it('caches separately for what cacheVary() returns', function (): void {
    renderAgain();

    app()->setLocale('fr');

    // A miss, though the author's tags still come from the relation cache.
    expect(renderAgain()['queries'])->toBe(3)
        ->and(renderAgain()['queries'])->toBe(0);
});

it('caches separately for the requested includes, with remember() on the collection', function (): void {
    $remembered = fn ($posts) => IncludablePostResource::collection($posts)->remember(300);

    $plain = renderAgain([], $remembered);
    $withComments = renderAgain(['include' => 'comments'], $remembered);

    expect($plain['data'][0])->toBe(['id' => 1])
        ->and($withComments['data'][0])->toHaveKey('comments')
        ->and(renderAgain(['include' => 'comments'], $remembered)['queries'])->toBe(0);
});

it('keys an entry on the order of the models it renders', function (): void {
    renderAgain();

    $reversed = renderAgain(resource: fn ($posts) => RememberedPostResource::collection($posts->reverse()->values()));

    expect(array_column($reversed['data'], 'id'))->toBe([2, 1])
        ->and($reversed['queries'])->toBeGreaterThan(0);
});

it('remembers a single resource and keeps pagination meta', function (): void {
    app()->instance('request', Request::create('/posts/1'));

    $single = fn (): array => (new RememberedPostResource(Post::query()->findOrFail(1)))->resolve();

    $first = $single();
    app()->forgetScopedInstances();
    $queries = $this->countQueries();

    expect($single())->toBe($first)
        ->and($queries())->toBe(1);

    $page = RememberedPostResource::collection(Post::query()->orderBy('id')->paginate(2))
        ->response(Request::create('/posts'))
        ->getData(true);

    expect($page['data'])->toHaveCount(2)
        ->and($page['meta']['total'])->toBe(3);
});

it('gives every authenticated user their own entry', function (): void {
    $render = function (?User $user, bool $shared = false): int {
        app()->forgetScopedInstances();

        $request = Request::create('/posts');
        app()->instance('request', $request);
        // Set after binding: rebinding the request resets its user resolver.
        $request->setUserResolver(fn (): ?User => $user);

        $posts = Post::query()->where('user_id', 1)->orderBy('id')->get();
        $queries = test()->countQueries();

        RememberedPostResource::collection($posts)->remember(300, shared: $shared)->response($request);

        return $queries();
    };

    $ada = User::query()->findOrFail(1);
    $grace = User::query()->findOrFail(2);

    expect($render($ada))->toBeGreaterThan(0)
        ->and($render($ada))->toBe(0)
        ->and($render($grace))->toBeGreaterThan(0)
        ->and($render(null, shared: true))->toBeGreaterThan(0)
        ->and($render($grace, shared: true))->toBe(0);
});
