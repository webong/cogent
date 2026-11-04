<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Webong\Cogent\Attributes\Includes;
use Webong\Cogent\Resources\PreparedResourceCollection;
use Webong\Cogent\Resources\ResourcePlan;
use Webong\Cogent\Tests\Fixtures\Models\Post;
use Webong\Cogent\Tests\Fixtures\Models\Tag;
use Webong\Cogent\Tests\Fixtures\Models\User;
use Webong\Cogent\Tests\Fixtures\Resources\PostResource;
use Webong\Cogent\Tests\Fixtures\Resources\PostResourceCollection;
use Webong\Cogent\Tests\Fixtures\Resources\PostWithCommentsResource;
use Webong\Cogent\Tests\Fixtures\Resources\UserResource;

beforeEach(function (): void {
    ResourcePlan::flush();
    PostWithCommentsResource::$prepared = [];
});

/**
 * @return array<string, mixed>
 */
function resolveResponse(mixed $resource): array
{
    return $resource->response(Request::create('/posts'))->getData(true);
}

function seedAuthors(int $posts): void
{
    $ada = User::query()->create(['name' => 'Ada']);
    $grace = User::query()->create(['name' => 'Grace']);

    Tag::query()->create(['taggable_type' => User::class, 'taggable_id' => $ada->id, 'name' => 'math']);

    foreach (range(1, $posts) as $index) {
        $post = Post::query()->create([
            'user_id' => $index % 2 === 0 ? $grace->id : $ada->id,
            'title' => 'Post '.$index,
        ]);

        createComments($post, $index);
    }
}

it('reads includes and counts declared with attributes', function (): void {
    $plan = ResourcePlan::for(PostResource::class);

    expect($plan->includes)->toBe(['author' => UserResource::class])
        ->and($plan->counts)->toBe(['comments'])
        ->and($plan->hasHook)->toBeFalse();
});

it('normalizes relation names and resource maps on #[Includes]', function (): void {
    $includes = new Includes('author', ['comments' => PostResource::class, 'tags'], 'author');

    expect($includes->relations)->toBe(['author' => null, 'comments' => PostResource::class, 'tags' => null]);
});

it('returns a preparing collection from collection()', function (): void {
    expect(PostResource::collection([]))->toBeInstanceOf(PreparedResourceCollection::class);
});

it('prepares a collection with a fixed number of queries however many posts it renders', function (int $posts): void {
    seedAuthors($posts);

    $collection = Post::query()->orderBy('id')->get();
    $queries = $this->countQueries();

    $data = resolveResponse(PostResource::collection($collection));

    // author, comment counts, the author's tags (a cache miss) and the author's post counts.
    expect($queries())->toBe(4)
        ->and($data['data'])->toHaveCount($posts)
        ->and($data['data'][0])->toMatchArray([
            'title' => 'Post 1',
            'comments_count' => 1,
            'author' => ['id' => 1, 'name' => 'Ada', 'tags' => ['math'], 'posts_count' => intdiv($posts + 1, 2)],
        ]);
})->with([1, 4, 12]);

it('shares one author instance across the posts it was loaded for', function (): void {
    seedAuthors(3);

    $collection = Post::query()->orderBy('id')->get();

    resolveResponse(PostResource::collection($collection));

    expect($collection[0]->author)->toBe($collection[2]->author);
});

it('finds a resource by the App\\Http\\Resources naming convention', function (): void {
    expect(ResourcePlan::resourceFor(Webong\Cogent\Tests\Fixtures\Conventions\Models\Billing\Invoice::class))
        ->toBe(Webong\Cogent\Tests\Fixtures\Conventions\Http\Resources\Billing\InvoiceResource::class);
});

it('prepares collections built by toResourceCollection() and paginated collections', function (): void {
    seedAuthors(3);

    $queries = $this->countQueries();
    $paginated = resolveResponse(PostResource::collection(Post::query()->orderBy('id')->paginate(2)));

    // count + page, then author, comment counts, tags and post counts.
    expect($queries())->toBe(6)
        ->and($paginated['data'])->toHaveCount(2)
        ->and($paginated['data'][1]['comments_count'])->toBe(2)
        ->and($paginated['meta']['total'])->toBe(3);

    $data = resolveResponse(Post::query()->orderBy('id')->get()->toResourceCollection(PostResource::class));

    expect($data['data'][2]['author']['name'])->toBe('Ada');
})->skip(! supportsUseResource(), 'toResourceCollection() needs Laravel 12');

it('prepares a ResourceCollection subclass through the resource it collects', function (): void {
    seedAuthors(2);

    $data = resolveResponse(new PostResourceCollection(Post::query()->orderBy('id')->get()));

    expect($data['data'][1])->toMatchArray(['comments_count' => 2, 'author' => ['id' => 2, 'name' => 'Grace', 'tags' => [], 'posts_count' => 1]]);
});

it('prepares a single resource', function (): void {
    seedAuthors(2);

    $post = Post::query()->findOrFail(2);
    $queries = $this->countQueries();

    $data = resolveResponse(new PostResource($post));

    expect($queries())->toBe(4)
        ->and($data['data'])->toMatchArray(['comments_count' => 2, 'author' => ['id' => 2, 'name' => 'Grace', 'tags' => [], 'posts_count' => 1]]);
});

it('skips relations and counts that are already loaded', function (): void {
    seedAuthors(2);

    $collection = Post::query()->with('author.tags')->withCount('comments')->orderBy('id')->get();
    $queries = $this->countQueries();

    resolveResponse(PostResource::collection($collection));

    // Only the author's post counts were missing.
    expect($queries())->toBe(1);
});

it('reads cacheable relations from the cache on the next request', function (): void {
    seedAuthors(2);

    resolveResponse(PostResource::collection(Post::query()->orderBy('id')->get()));

    app()->forgetScopedInstances();

    $collection = Post::query()->orderBy('id')->get();
    $queries = $this->countQueries();

    $data = resolveResponse(PostResource::collection($collection));

    // author, comment counts and post counts: the tags came from the cache.
    expect($queries())->toBe(3)
        ->and($data['data'][0]['author']['tags'])->toBe(['math']);
});

it('prepares nested resources found through #[UseResource] on the related model', function (): void {
    seedAuthors(3);

    $collection = Post::query()->orderBy('id')->get();
    $queries = $this->countQueries();

    $data = resolveResponse(PostWithCommentsResource::collection($collection));

    // comments, then every comment's post: CommentResource is found on Comment.
    expect($queries())->toBe(2)
        ->and($data['data'][2]['comments'])->toHaveCount(3)
        ->and($data['data'][2]['comments'][0])->toMatchArray(['body' => 'Comment #1', 'post_title' => 'Post 3']);
})->skip(! supportsUseResource(), '#[UseResource] needs Laravel 12');

it('calls a static prepare() hook once with every model and the request', function (): void {
    seedAuthors(3);

    $data = resolveResponse(PostWithCommentsResource::collection(Post::query()->orderBy('id')->get()));

    expect(PostWithCommentsResource::$prepared)->toBe([['models' => 3, 'path' => 'posts']])
        ->and(array_column($data['data'], 'headline'))->toBe(['POST 1', 'POST 2', 'POST 3']);
});

it('leaves resources without a plan and empty collections alone', function (): void {
    $queries = $this->countQueries();

    $data = resolveResponse(PostResource::collection(Post::query()->whereRaw('1 = 0')->get()));

    expect($data['data'])->toBe([])
        ->and($queries())->toBe(1)
        ->and(ResourcePlan::for(JsonResource::class)->isEmpty())->toBeTrue();
});
