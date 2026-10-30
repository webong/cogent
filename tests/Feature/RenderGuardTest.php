<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Webong\Cogent\Cogent;
use Webong\Cogent\Exceptions\QueryWhileRenderingException;
use Webong\Cogent\Resources\ResourcePlan;
use Webong\Cogent\Tests\Fixtures\Models\Post;
use Webong\Cogent\Tests\Fixtures\Models\User;
use Webong\Cogent\Tests\Fixtures\Resources\LeakyPostResource;
use Webong\Cogent\Tests\Fixtures\Resources\LeakyUserResource;
use Webong\Cogent\Tests\Fixtures\Resources\PostResource;

beforeEach(function (): void {
    ResourcePlan::flush();
    Cogent::flush();
    LeakyPostResource::$leak = true;

    createComments(createPosts(User::query()->create(['name' => 'Ada']), 2)->first(), 2);
});

afterEach(function (): void {
    Cogent::flush();
});

/**
 * @return array<string, mixed>
 */
function renderPosts(string $resource): array
{
    return $resource::collection(Post::query()->orderBy('id')->get())
        ->response(Request::create('/posts'))
        ->getData(true);
}

it('lets queries through while rendering until it is turned on', function (): void {
    expect(renderPosts(LeakyPostResource::class)['data'][0]['comments'])->toBe(2);
});

it('throws when a guarded resource queries while it renders', function (): void {
    Cogent::guardResources();

    renderPosts(LeakyPostResource::class);
})->throws(QueryWhileRenderingException::class, '['.LeakyPostResource::class.'] ran a query while rendering: select * from "comments"');

it('lets a resource that loaded everything render, with its preparing queries', function (): void {
    Cogent::guardResources();

    expect(renderPosts(PostResource::class)['data'][1])->toMatchArray(['id' => 2, 'comments_count' => 0]);
});

it('blames the innermost resource, through a custom handler', function (): void {
    $reported = [];

    Cogent::guardResources();
    Cogent::handleResourceQueriesUsing(function (string $resource, QueryExecuted $query) use (&$reported): void {
        $reported[] = [$resource, $query->sql];
    });
    LeakyPostResource::$leak = false;

    $data = renderPosts(LeakyPostResource::class);

    expect($data['data'][0]['author']['posts'])->toBe(2)
        ->and(array_column($reported, 0))->toBe([LeakyUserResource::class, LeakyUserResource::class])
        ->and($reported[0][1])->toContain('from "posts"');
});

it('stops watching when turned off again', function (): void {
    Cogent::guardResources();
    Cogent::guardResources(false);

    expect(renderPosts(LeakyPostResource::class)['data'])->toHaveCount(2);
});
