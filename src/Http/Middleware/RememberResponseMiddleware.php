<?php

declare(strict_types=1);

namespace Webong\Cogent\Http\Middleware;

use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Webong\Cogent\Attributes\RememberResponse;
use Webong\Cogent\Cache\CacheKeyBuilder;

/**
 * Reads #[RememberResponse] on controller methods and caches the response.
 *
 * Runs after route model binding so bound models are available for the cache
 * key and automatic invalidation.
 */
final class RememberResponseMiddleware
{
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly CacheKeyBuilder $keys,
    ) {}

    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        $route = $request->route();

        if ($route === null) {
            return $next($request);
        }

        $action = $route->action();
        $controller = $action['controller'] ?? null;

        if (! is_string($controller) || ! str_contains($controller, '@')) {
            return $next($request);
        }

        [$class, $method] = explode('@', $controller, 2);

        $attribute = null;

        try {
            $reflection = new \ReflectionMethod($class, $method);
            $attribute = $reflection->getAttributes(RememberResponse::class)[0] ?? null;
        } catch (\ReflectionException) {
            return $next($request);
        }

        if ($attribute === null) {
            return $next($request);
        }

        $config = $attribute->newInstance();

        // Vary values read from the request.
        $varyValues = [];

        foreach ($config->vary as $key) {
            $varyValues[$key] = $request->input($key);
        }

        // If not shared, vary by the authenticated user.
        if (! $config->shared) {
            $user = $request->user();

            if ($user !== null) {
                $varyValues['user'] = $user->getAuthIdentifier();
            }
        }

        // Route-bound models from route model binding.
        $models = [];

        foreach ($route->parameters() as $parameter) {
            if ($parameter instanceof Model) {
                $models[] = $parameter;
            }
        }

        $identities = implode(',', array_map(
            static fn (Model $model): string => $model::class . ':' . $model->getKey(),
            $models,
        ));

        $cacheKey = $this->keys->build(
            'cogent',
            'controller',
            $route->getName() ?? $route->uri(),
            ['models' => $identities, 'vary' => $varyValues],
        );

        // Cache hit: return the stored response without running the controller.
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null && is_string($cached)) {
            return new SymfonyResponse(
                $cached,
                200,
                ['content-type' => 'application/json'],
            );
        }

        // Cache miss: run the controller and store the response content.
        $response = $next($request);

        if ($response instanceof SymfonyResponse && $response->getStatusCode() === 200) {
            $this->cache->put($cacheKey, $response->getContent(), $config->ttl);
        }

        return $response;
    }
}
