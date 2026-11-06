<?php

declare(strict_types=1);

use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;
use Webong\Cogent\QueryBuilder\QueryBuilderMetadata;
use Webong\Cogent\Tests\Fixtures\Models\Post;

beforeEach(function (): void {
    // No setup needed
});

it('reads allowed includes from a QueryBuilder', function (): void {
    $qb = QueryBuilder::for(Post::query())
        ->allowedIncludes('author', 'comments');

    $metadata = QueryBuilderMetadata::from($qb);

    expect($metadata['includes'])->toHaveKeys(['author', 'comments']);
});

it('reads allowed filters from a QueryBuilder', function (): void {
    $qb = QueryBuilder::for(Post::query())
        ->allowedFilters(AllowedFilter::exact('title'), AllowedFilter::partial('body'));

    $metadata = QueryBuilderMetadata::from($qb);

    expect($metadata['filters'])->toHaveKeys(['title', 'body']);
});

it('reads allowed sorts from a QueryBuilder', function (): void {
    $qb = QueryBuilder::for(Post::query())
        ->allowedSorts('title', 'created_at');

    $metadata = QueryBuilderMetadata::from($qb);

    expect($metadata['sorts'])->toHaveKeys(['title', 'created_at']);
});

it('returns empty arrays for a QueryBuilder with no declared includes/filters/sorts', function (): void {
    $qb = QueryBuilder::for(Post::query());

    $metadata = QueryBuilderMetadata::from($qb);

    expect($metadata['includes'])->toBe([])
        ->and($metadata['filters'])->toBe([])
        ->and($metadata['sorts'])->toBe([]);
});

it('reads mixed includes with counts and relationships', function (): void {
    $qb = QueryBuilder::for(Post::query())
        ->allowedIncludes('author', 'commentsCount');

    $metadata = QueryBuilderMetadata::from($qb);

    expect($metadata['includes'])->toHaveKeys(['author', 'commentsCount']);
});

it('openApi includes filter and sort parameters when QueryBuilder metadata is provided', function (): void {
    if (! \Webong\Cogent\Documentation\ResourceSchemaInferer::available()) {
        expect(true)->toBeTrue();

        return;
    }

    $qb = QueryBuilder::for(Post::query())
        ->allowedFilters(AllowedFilter::exact('title'))
        ->allowedSorts('created_at')
        ->allowedIncludes('author');

    $metadata = QueryBuilderMetadata::from($qb);

    $exporter = new \Webong\Cogent\Resources\PlanExporter();
    $document = $exporter->openApi(
        [\Webong\Cogent\Tests\Fixtures\Resources\PostResource::class],
        'include',
        [\Webong\Cogent\Tests\Fixtures\Resources\PostResource::class => $metadata],
    );

    $parameters = (array) $document['components']['parameters'];

    expect($document['openapi'])->toBe('3.1.0')
        ->and($parameters)->toHaveKey('PostResourceFilterTitle')
        ->and($parameters)->toHaveKey('PostResourceSort');
});
