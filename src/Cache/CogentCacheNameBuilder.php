<?php

declare(strict_types=1);

namespace Webong\Cogent\Cache;

/**
 * Builds cache keys and tags for Cogent's controller response cache.
 *
 * Keys follow the pattern: cogent:{domain}:{surface}:{segments}:sha1(parameters)
 * Tags follow: cogent:surface:{surface} and cogent:{domain}:{tag}:{value}
 *
 * Mirrors the CRM's InboxCacheNameBuilder pattern so both packages share
 * the same mental model for cache key and tag construction.
 */
final class CogentCacheNameBuilder
{
    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<int|string, mixed>  $segments
     */
    public function getKey(
        string $domain,
        string $surface,
        array $parameters = [],
        array $segments = [],
    ): string {
        $this->assertSurfaceSupported($domain, $surface);

        $prefix = 'cogent';

        $prefix .= $this->buildSegmentPath($segments);

        $prefix .= ":{$domain}:{$surface}";

        return $this->buildKey($prefix, $parameters);
    }

    public function getSurfaceTag(string $surface): string
    {
        return "cogent:surface:{$surface}";
    }

    public function getDomainSurfaceTag(string $domain, string $surface): string
    {
        $this->assertSurfaceSupported($domain, $surface);

        return "cogent:{$domain}:surface:{$surface}";
    }

    public function getDomainTag(string $domain, string|int $value): string
    {
        return "cogent:{$domain}:{$value}";
    }

    public function getDomainScopedTag(string $domain, string $scope, string|int $value): string
    {
        return "cogent:{$domain}:{$scope}:{$value}";
    }

    /**
     * @param  array<int|string, mixed>  $segments
     */
    private function buildSegmentPath(array $segments): string
    {
        $parts = [];

        foreach ($segments as $name => $value) {
            if (! is_string($value) && ! is_int($value)) {
                continue;
            }

            if (is_string($name)) {
                if ($name === '') {
                    continue;
                }

                $parts[] = $name;
                $parts[] = (string) $value;

                continue;
            }

            $parts[] = (string) $value;
        }

        return $parts === [] ? '' : ':' . implode(':', $parts);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function buildKey(string $prefix, array $parameters): string
    {
        if ($parameters === []) {
            return $prefix;
        }

        $encoded = json_encode(
            $this->normalizeArray($parameters),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        return "{$prefix}:" . sha1($encoded);
    }

    private function assertSurfaceSupported(string $domain, string $surface): void
    {
        $config = config('cogent', []);

        $domains = $config['domains'] ?? [];
        if (! in_array($domain, $domains, true)) {
            throw new \InvalidArgumentException("Unsupported cogent cache domain [{$domain}]");
        }

        $registry = $config['registry'] ?? [];
        $domainSurfaces = $registry[$domain] ?? [];
        if (! in_array($surface, $domainSurfaces, true)) {
            throw new \InvalidArgumentException("Unsupported cogent cache surface [{$domain}.{$surface}]");
        }
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function normalizeArray(array $parameters): array
    {
        ksort($parameters);

        foreach ($parameters as $key => $value) {
            if (is_array($value)) {
                $parameters[$key] = $this->normalizeList($value);

                continue;
            }

            $parameters[$key] = $this->normalizeScalar($value);
        }

        return $parameters;
    }

    /**
     * @param  array<int|string, mixed>  $values
     */
    private function normalizeList(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = $this->normalizeList($value);

                continue;
            }

            $values[$key] = $this->normalizeScalar($value);
        }

        if (array_is_list($values)) {
            sort($values);
        } else {
            ksort($values);
        }

        return $values;
    }

    private function normalizeScalar(mixed $value): mixed
    {
        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }

        return (string) $value;
    }
}
