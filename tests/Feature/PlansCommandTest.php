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
