<?php

declare(strict_types=1);

namespace Webong\Cogent;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Application wide Cogent settings, meant for a service provider's boot().
 */
final class Cogent
{
    private static string $includeParameter = 'include';

    /**
     * Read the relations a request asks for from another query parameter than ?include=.
     */
    public static function includeParameter(string $name): void
    {
        self::$includeParameter = $name;
    }

    /**
     * The relation paths a request asks for, from ?include=a,b.c or ?include[]=a.
     *
     * @return list<string>
     */
    public static function requestedIncludes(Request $request): array
    {
        $value = $request->query(self::$includeParameter);

        $paths = is_array($value) ? $value : explode(',', is_string($value) ? $value : '');

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $path): string => is_string($path) ? Str::trim($path) : '', $paths),
            static fn (string $path): bool => $path !== '',
        )));
    }

    public static function flush(): void
    {
        self::$includeParameter = 'include';
    }
}
