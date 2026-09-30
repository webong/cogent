<?php

declare(strict_types=1);

namespace Webong\Cogent\Cache;

final class CacheKeyBuilder
{
    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<int|string, mixed>  $segments
     */
    public function build(string $namespace, string $domain, string $surface, array $parameters = [], array $segments = []): string
    {
        $prefix = implode(':', [$namespace, ...$this->buildSegmentPath($segments), $domain, $surface]);

        if ($parameters === []) {
            return $prefix;
        }

        $encoded = json_encode(
            $this->normalizeArray($parameters),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        return "{$prefix}:".sha1($encoded);
    }

    /**
     * @param  array<int|string, mixed>  $segments
     * @return array<int, string>
     */
    private function buildSegmentPath(array $segments): array
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
            }

            $parts[] = (string) $value;
        }

        return $parts;
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    private function normalizeArray(array $parameters): array
    {
        ksort($parameters);

        foreach ($parameters as $key => $value) {
            $parameters[$key] = is_array($value)
                ? $this->normalizeList($value)
                : $this->normalizeScalar($value);
        }

        return $parameters;
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @return array<int|string, mixed>
     */
    private function normalizeList(array $values): array
    {
        foreach ($values as $key => $value) {
            $values[$key] = is_array($value)
                ? $this->normalizeList($value)
                : $this->normalizeScalar($value);
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
