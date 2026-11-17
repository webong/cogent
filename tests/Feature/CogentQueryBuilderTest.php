<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Webong\Cogent\QueryBuilder\CogentQueryBuilder;
use Webong\Cogent\Resources\ResourcePlan;
use Webong\Cogent\Tests\Fixtures\Models\BroadcastingPost;
use Webong\Cogent\Tests\Fixtures\Models\Post;
use Webong\Cogent\Tests\Fixtures\Models\User;
use Webong\Cogent\Tests\Fixtures\Resources\PostResource;

beforeEach(function (): void {
    ResourcePlan::flush();
});

function makeQueryBuilderWithAttributes(): QueryBuilder
{
    return QueryBuilder::for(Post::query())
        ->allowedFilters(AllowedFilter::exact('title'))
        ->allowedSorts('created_at')
        ->allowedIncludes('author');
}

it('Post::interpret(Resource) returns a CogentQueryBuilder', function (): void {
    $qb = Post::interpret(PostResource::class);

    expect($qb)->toBeInstanceOf(CogentQueryBuilder::class);
});

it('Post::query()->interpret(Resource) returns a CogentQueryBuilder', function (): void {
    $qb = Post::query()->interpret(PostResource::class);

    expect($qb)->toBeInstanceOf(CogentQueryBuilder::class);
});

it('Post::interpret() guesses the resource from the model', function (): void {
    $qb = BroadcastingPost::interpret();

    expect($qb)->toBeInstanceOf(CogentQueryBuilder::class);
});

it('Post::interpret() throws when the model has no resource', function (): void {
    Post::interpret()->explainer();
})->throws(InvalidArgumentException::class);

it('CogentQueryBuilder::for() with model class name', function (): void {
    $qb = CogentQueryBuilder::for(Post::class, PostResource::class);

    expect($qb)->toBeInstanceOf(CogentQueryBuilder::class);
});

it('reads metadata from resource attributes', function (): void {
    $metadata = CogentQueryBuilder::metadataFor(PostResource::class);

    expect($metadata['includes'])->toBeArray();
    expect($metadata['filters'])->toBeArray();
    expect($metadata['sorts'])->toBeArray();
});

it('applies allowed filters from #[Filterable] attribute', function (): void {
    $metadata = CogentQueryBuilder::metadataFor(PostResource::class);

    expect($metadata)->toHaveKeys(['includes', 'filters', 'sorts']);
});

it('get() returns an Eloquent collection', function (): void {
    $ada = User::query()->create(['name' => 'Ada']);
    Post::query()->create(['user_id' => $ada->id, 'title' => 'First post']);

    $qb = Post::interpret(PostResource::class);

    $collection = $qb->get();

    expect($collection)->toBeInstanceOf(EloquentCollection::class);
    expect($collection)->toHaveCount(1);
});

it('paginate() returns a paginator', function (): void {
    $ada = User::query()->create(['name' => 'Ada']);
    Post::query()->create(['user_id' => $ada->id, 'title' => 'First post']);

    $qb = Post::interpret(PostResource::class);

    $paginator = $qb->paginate(15);

    expect($paginator)->toBeInstanceOf(\Illuminate\Pagination\LengthAwarePaginator::class);
    expect($paginator->items())->toHaveCount(1);
});

it('cursorPaginate() returns a cursor paginator', function (): void {
    $ada = User::query()->create(['name' => 'Ada']);
    Post::query()->create(['user_id' => $ada->id, 'title' => 'First post']);

    $qb = Post::interpret(PostResource::class);

    $paginator = $qb->cursorPaginate(15);

    expect($paginator)->toBeInstanceOf(\Illuminate\Pagination\CursorPaginator::class);
    expect($paginator->items())->toHaveCount(1);
});

it('explainer() returns the underlying QueryBuilder', function (): void {
    $qb = Post::interpret(PostResource::class);

    $explainer = $qb->explainer();

    expect($explainer)->toBeInstanceOf(QueryBuilder::class);
});

it('metadataFor includes spatie QueryBuilder metadata', function (): void {
    $metadata = CogentQueryBuilder::metadataFor(PostResource::class);

    expect($metadata)->toHaveKeys(['includes', 'filters', 'sorts']);
    expect($metadata['includes'])->toBeArray();
    expect($metadata['filters'])->toBeArray();
    expect($metadata['sorts'])->toBeArray();
});
