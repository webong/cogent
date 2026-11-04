<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Webong\Cogent\Cogent;
use Webong\Cogent\Resources\ResourcePlan;
use Webong\Cogent\Tests\Fixtures\Models\Post;
use Webong\Cogent\Tests\Fixtures\Models\User;
use Webong\Cogent\Tests\Fixtures\Resources\IncludablePostResource;
use Webong\Cogent\Tests\Fixtures\Resources\UserResource;

beforeEach(function (): void {
    ResourcePlan::flush();
    Cogent::flush();

    $author = User::query()->create(['name' => 'Ada']);

    foreach (createPosts($author, 2) as $post) {
        createComments($post, 2);
    }
});

afterEach(function (): void {
    Cogent::flush();
});

/**
 * Resolve a response the way a request would, including the nested resources
 * that read the request from the container while the response is encoded.
 *
 * @param  array<string, mixed>  $query
 * @return list<array<string, mixed>>
 */
function includeResponse(array $query): array
{
    $request = Request::create('/posts', 'GET', $query);

    app()->instance('request', $request);

    return IncludablePostResource::collection(Post::query()->orderBy('id')->get())
        ->response($request)
        ->getData(true)['data'];
}

it('reads requested includes from a comma separated or an array parameter', function (): void {
    expect(Cogent::requestedIncludes(Request::create('/', 'GET', ['include' => 'author, comments.post,,author'])))
        ->toBe(['author', 'comments.post'])
        ->and(Cogent::requestedIncludes(Request::create('/', 'GET', ['include' => ['author', ' tags ']])))
        ->toBe(['author', 'tags'])
        ->and(Cogent::requestedIncludes(Request::create('/')))->toBe([]);
});

it('resolves which declared paths a request turns on', function (): void {
    $plan = new ResourcePlan(includes: ['tags' => null], includable: ['author.posts' => null, 'comments' => UserResource::class]);

    expect($plan->includesFor([]))->toBe(['tags' => null])
        ->and($plan->includesFor(['author']))->toBe(['tags' => null, 'author' => null])
        ->and($plan->includesFor(['author.posts', 'comments.post', 'secret']))
        ->toBe(['tags' => null, 'author.posts' => null, 'comments' => UserResource::class]);
});

it('loads nothing that was not asked for', function (): void {
    $queries = $this->countQueries();

    $data = includeResponse([]);

    // Only the posts themselves.
    expect($queries())->toBe(1)
        ->and($data[0])->toBe(['id' => 1]);
});

it('loads a requested relation and the plan of the resource that renders it', function (): void {
    $queries = $this->countQueries();

    $data = includeResponse(['include' => 'author']);

    // posts, author, the author's tags and post counts.
    expect($queries())->toBe(4)
        ->and($data[0])->toBe(['id' => 1, 'author' => ['id' => 1, 'name' => 'Ada', 'tags' => [], 'posts_count' => 2]]);
});

it('passes nested includes on to the resource that renders the relation', function (): void {
    $queries = $this->countQueries();

    $data = includeResponse(['include' => 'author.posts,comments']);

    // posts, author, tags, the author's posts, post counts, comments and, for
    // CommentResource found through #[UseResource] on Laravel 12, every comment's post.
    expect($queries())->toBe(supportsUseResource() ? 7 : 6)
        ->and($data[1]['author']['posts'])->toBe(['First post', 'Second post'])
        ->and($data[1]['comments'])->toBe(['Comment #1', 'Comment #2']);
});

it('ignores includes that are not declared', function (): void {
    $queries = $this->countQueries();

    $data = includeResponse(['include' => 'secret,author.secret']);

    expect($queries())->toBe(4)
        ->and($data[0]['author'])->not->toHaveKey('posts');
});

it('does not let a nested resource read the root request as its own', function (): void {
    // posts is includable on UserResource, but this request asks for it on posts.
    $data = includeResponse(['include' => 'author,posts']);

    expect($data[0]['author'])->not->toHaveKey('posts');
});

it('reads includes from a custom query parameter', function (): void {
    Cogent::includeParameter('with');

    $data = includeResponse(['with' => ['comments'], 'include' => 'author']);

    expect($data[0])->toBe(['id' => 1, 'comments' => ['Comment #1', 'Comment #2']]);
});
