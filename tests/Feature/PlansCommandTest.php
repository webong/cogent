<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Webong\Cogent\Resources\PlanExporter;
use Webong\Cogent\Resources\ResourcePlan;
use Webong\Cogent\Tests\Fixtures\Resources\CommentResource;
use Webong\Cogent\Tests\Fixtures\Resources\IncludablePostResource;
use Webong\Cogent\Tests\Fixtures\Resources\PostResource;
use Webong\Cogent\Tests\Fixtures\Resources\RememberedPostResource;
use Webong\Cogent\Tests\Fixtures\Resources\UserResource;

beforeEach(function (): void {
    ResourcePlan::flush();
});

function fixtureResources(): string
{
    return dirname(__DIR__).'/Fixtures/Resources';
}

it('finds every resource that prepares itself', function (): void {
    $resources = (new PlanExporter)->discover([fixtureResources(), '/does/not/exist']);

    expect($resources)->toContain(PostResource::class, CommentResource::class, UserResource::class)
        ->and($resources)->each->toBeString();
});

it('describes a plan with every path a request may include', function (): void {
    expect((new PlanExporter)->describe(IncludablePostResource::class))->toBe([
        'resource' => IncludablePostResource::class,
        'includes' => [],
        'includable' => ['author' => UserResource::class, 'comments' => null],
        'counts' => [],
        'prepare' => false,
        'remember' => null,
        'requestable' => ['author', 'author.posts', 'comments'],
    ])
        ->and((new PlanExporter)->describe(RememberedPostResource::class))->toMatchArray([
            'counts' => ['comments'],
            'remember' => ['ttl' => 300, 'shared' => false],
            'requestable' => ['author.posts'],
        ]);
});

it('prints the plans as JSON', function (): void {
    Artisan::call('cogent:plans', ['--path' => [fixtureResources()], '--json' => true]);

    $plans = collect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->keyBy('resource');

    expect($plans[PostResource::class]['includes'])->toBe(['author' => UserResource::class])
        ->and($plans[UserResource::class]['requestable'])->toBe(['posts']);
});

it('prints the plans as a table', function (): void {
    $this->artisan('cogent:plans', ['--path' => [fixtureResources()]])
        ->expectsOutputToContain('RememberedPostResource')
        ->assertSuccessful();
});

it('describes include parameters as OpenAPI components', function (): void {
    $document = (new PlanExporter)->openApi([IncludablePostResource::class, CommentResource::class], 'with');
    $parameters = (array) $document['components']['parameters'];

    expect($document['openapi'])->toBe('3.1.0')
        ->and(array_keys($parameters))->toBe(['IncludablePostResourceInclude'])
        ->and($parameters['IncludablePostResourceInclude'])->toMatchArray(['name' => 'with', 'in' => 'query', 'explode' => false])
        ->and($parameters['IncludablePostResourceInclude']['schema']['items']['enum'])->toBe(['author', 'author.posts', 'comments'])
        ->and(((array) $document['x-cogent']['resources'])['CommentResource']['includes'])->toBe(['post' => null]);
});

it('infers resource response schemas with Scramble', function (): void {
    $document = (new PlanExporter)->openApi([PostResource::class]);

    $document = json_decode(json_encode($document, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($document['components']['schemas'])->toHaveKey('PostResource')
        ->and($document['components']['schemas']['PostResource']['properties']['title']['type'])->toBe('string')
        ->and($document['components']['schemas']['PostResource']['properties']['author']['anyOf'][0]['$ref'])
        ->toBe('#/components/schemas/UserResource');
});

it('prints OpenAPI components', function (): void {
    Artisan::call('cogent:plans', ['--path' => [fixtureResources()], '--openapi' => true]);

    $document = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($document['components']['parameters'])->toHaveKey('IncludablePostResourceInclude')
        ->and($document['components']['parameters']['IncludablePostResourceInclude']['name'])->toBe('include');
});
