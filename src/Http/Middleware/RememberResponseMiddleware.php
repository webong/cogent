<?php

declare(strict_types=1);

namespace Webong\Cogent\Http\Middleware;

use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Webong\Cogent\Attributes\RememberResponse;
use Webong\Cogent\Cache\CogentCacheNameBuilder;

/**
 * Reads #[RememberResponse] on controller methods and caches the response
 * using the CRM-style surface/domain/tag pattern.
 *
 * The cache key is built from the domain, surface, route-bound models, and
 * vary values (which can be request input keys or closures receiving the
 * request). Tags are used for automatic invalidation when models change.
 *
 * Runs after route model binding so bound models are available for the cache
 * key and tags.
 */
final class RememberResponseMiddleware
{
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly CogentCacheNameBuilder $nameBuilder,
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

        // Conditional caching: skip if `when` returns false.
        if ($config->when !== null && ! ($config->when)($request)) {
            return $next($request);
        }

        // Auto-register domain/surface into the registry if not present.
        $this->ensureRegistryEntry($config->domain, $config->surface);

        // Check if this surface is enabled and get its TTL from config.
        $surfaces = config('cogent.surfaces', []);
        $surfaceConfig = $surfaces[$config->surface] ?? null;

        if (! $surfaceConfig || ! ($surfaceConfig['enabled'] ?? false)) {
            return $next($request);
        }

        $ttl = (int) ($surfaceConfig['ttl'] ?? 60);

        if ($ttl <= 0) {
            return $next($request);
        }

        // Vary values: request input keys or closures receiving the request.
        $varyValues = [];

        foreach ($config->vary as $vary) {
            if ($vary instanceof Closure) {
                $varyValues[get_class($vary) ?? 'closure'] = $vary($request);

                continue;
            }

            $varyValues[$vary] = $request->input($vary);
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

        $modelSegments = [];

        foreach ($models as $model) {
            $modelSegments[$model::class] = $model->getKey();
        }

        $parameters = [
            'vary' => $varyValues,
            'models' => $modelSegments,
        ];

        $cacheKey = $this->nameBuilder->getKey(
            domain: $config->domain,
            surface: $config->surface,
            parameters: $parameters,
        );

        // Build tags for invalidation.
        $tags = [
            $this->nameBuilder->getSurfaceTag($config->surface),
        ];

        if (! $config->shared) {
            $user = $request->user();

            if ($user !== null) {
                $tags[] = $this->nameBuilder->getDomainTag('users', $user->getAuthIdentifier());
            }
        }

        foreach ($models as $model) {
            $tags[] = $this->nameBuilder->getDomainTag($config->domain, $model->getKey());
        }

        // Cache hit: return the stored response without running the controller.
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null && is_string($cached)) {
            return new SymfonyResponse(
                $cached,
                200,
                ['content-type' => 'application/json'],
            );
        }

        // Cache miss: run the controller and store the response with tags.
        $response = $next($request);

        if ($response instanceof SymfonyResponse && $response->getStatusCode() === 200) {
            $this->cache->tags($tags)->put($cacheKey, $response->getContent(), $ttl);
        }

        return $response;
    }

    /**
     * Ensure the domain/surface combination exists in the registry so
     * config lookups don't throw. This lets attributes declare new
     * domains/surfaces without manually editing config/cogent.php.
     */
    private function ensureRegistryEntry(string $domain, string $surface): void
    {
        $registry = config('cogent.registry', []);

        if (isset($registry[$domain]) && in_array($surface, $registry[$domain], true)) {
            return;
        }

        $registry[$domain] = array_values(array_unique([
            ...($registry[$domain] ?? []),
            $surface,
        ]));

        config(['cogent.registry' => $registry]);

        $domains = config('cogent.domains', []);

        if (! in_array($domain, $domains, true)) {
            config(['cogent.domains' => array_values(array_unique([...$domains, $domain]))]);
        }
    }
}
