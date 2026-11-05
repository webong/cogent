<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Webong\Cogent\Attributes\RememberResponse;
use Webong\Cogent\Cache\CogentCacheInvalidator;
use Webong\Cogent\Cache\CogentCacheNameBuilder;
use Webong\Cogent\Http\Middleware\RememberResponseMiddleware;
use Webong\Cogent\Resources\OutputCache;
use Webong\Cogent\Resources\ResourcePlan;
use Webong\Cogent\Tests\Fixtures\Models\User;

beforeEach(function (): void {
    ResourcePlan::flush();
    config(['cache.default' => 'array']);
});

afterEach(function (): void {
    config(['cogent.registry' => [], 'cogent.domains' => []]);
    app()->forgetScopedInstances();
});

it('registers the remember.response middleware', function (): void {
    $middleware = app(RememberResponseMiddleware::class);

    expect($middleware)->toBeInstanceOf(RememberResponseMiddleware::class);
});

it('skips caching when no attribute on route action', function (): void {
    $middleware = makeMiddleware(
        registry: ['posts' => ['list']],
        domains: ['posts'],
        surfaces: ['list' => ['enabled' => true, 'ttl' => 300]],
    );

    $request = Request::create('/posts/1');

    $callCount = 0;
    $next = function (Request $request) use (&$callCount): SymfonyResponse {
        $callCount++;

        return new SymfonyResponse(
            json_encode(['title' => 'First post']),
            200,
            ['content-type' => 'application/json'],
        );
    };

    // No route set, so no attribute found - should just pass through
    $response = $middleware->handle($request, $next);

    expect($callCount)->toBe(1)
        ->and($response->getStatusCode())->toBe(200);
});

it('CogentCacheNameBuilder builds keys and tags', function (): void {
    config(['cogent.registry' => ['posts' => ['list', 'detail']], 'cogent.domains' => ['posts']]);

    $builder = app(CogentCacheNameBuilder::class);

    $key = $builder->getKey('posts', 'list', ['vary' => ['locale' => 'en'], 'models' => ['App\Models\Post' => 1]], ['user' => 1]);
    expect($key)->toBeString()
        ->and($key)->toContain(':posts:list:');

    $surfaceTag = $builder->getSurfaceTag('list');
    expect($surfaceTag)->toBe('cogent:surface:list');

    $domainTag = $builder->getDomainTag('posts', 1);
    expect($domainTag)->toBe('cogent:posts:1');

    $domainSurfaceTag = $builder->getDomainSurfaceTag('posts', 'detail');
    expect($domainSurfaceTag)->toBe('cogent:posts:surface:detail');

    $domainScopedTag = $builder->getDomainScopedTag('posts', 'detail', 1);
    expect($domainScopedTag)->toBe('cogent:posts:detail:1');
});

it('CogentCacheInvalidator has invalidation methods', function (): void {
    $invalidator = app(CogentCacheInvalidator::class);

    expect($invalidator)->toBeInstanceOf(Webong\Cogent\Cache\CogentCacheInvalidator::class);
    expect(method_exists($invalidator, 'invalidateUser'))->toBeTrue();
    expect(method_exists($invalidator, 'invalidatePost'))->toBeTrue();
    expect(method_exists($invalidator, 'invalidateComment'))->toBeTrue();
    expect(method_exists($invalidator, 'invalidateSurface'))->toBeTrue();
    expect(method_exists($invalidator, 'invalidateDomain'))->toBeTrue();
});

it('RememberResponse attribute has expected properties', function (): void {
    $attribute = new RememberResponse(
        surface: 'list',
        domain: 'posts',
        vary: ['locale', 'page'],
        shared: false,
        when: fn (Request $request) => true,
    );

    expect($attribute->surface)->toBe('list');
    expect($attribute->domain)->toBe('posts');
    expect($attribute->vary)->toBe(['locale', 'page']);
    expect($attribute->shared)->toBeFalse();
    expect($attribute->when)->toBeInstanceOf(Closure::class);
});

it('OutputCache remember stores and retrieves with tags', function (): void {
    $ada = User::query()->create(['name' => 'Ada']);
    $post = \Webong\Cogent\Tests\Fixtures\Models\Post::query()->create([
        'user_id' => $ada->id,
        'title' => 'First post',
    ]);

    $cache = app(OutputCache::class);

    $result = $cache->remember(
        resource: 'test:resource',
        models: [$post],
        vary: ['locale' => 'en'],
        ttl: 300,
        build: fn () => ['title' => 'First post'],
    );

    expect($result)->toBe(['title' => 'First post']);

    // Cache hit: build should not run again
    $buildCount = 0;
    $result2 = $cache->remember(
        resource: 'test:resource',
        models: [$post],
        vary: ['locale' => 'en'],
        ttl: 300,
        build: fn () => ($buildCount++) && ['title' => 'Second'],
    );

    expect($result2)->toBe(['title' => 'First post'])
        ->and($buildCount)->toBe(0);

    // Update the post: invalidates cache
    $post->update(['title' => 'Edited']);

    // Cache miss again
    $result3 = $cache->remember(
        resource: 'test:resource',
        models: [$post],
        vary: ['locale' => 'en'],
        ttl: 300,
        build: fn () => ['title' => 'Edited'],
    );

    expect($result3)->toBe(['title' => 'Edited']);
});

function makeMiddleware(
    array $registry = [],
    array $domains = [],
    array $surfaces = []
): RememberResponseMiddleware {
    config(['cogent.registry' => $registry, 'cogent.domains' => $domains, 'cogent.surfaces' => $surfaces]);

    return app(RememberResponseMiddleware::class);
}
