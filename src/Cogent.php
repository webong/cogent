<?php

declare(strict_types=1);

namespace Webong\Cogent;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Webong\Cogent\Resources\BroadcastLimit;
use Webong\Cogent\Resources\RenderGuard;

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

    /**
     * Report queries a prepared resource runs while it renders, like
     * Model::preventLazyLoading() does for lazy loads. Meant for local and testing.
     */
    public static function guardResources(bool $value = true): void
    {
        RenderGuard::enable($value);
    }

    /**
     * Handle a query while rendering instead of throwing QueryWhileRenderingException.
     *
     * @param  (Closure(class-string, QueryExecuted): void)|null  $handler
     */
    public static function handleResourceQueriesUsing(?Closure $handler): void
    {
        RenderGuard::handleUsing($handler);
    }

    /**
     * Check payloads built from resources for broadcasting against a size, in
     * bytes of JSON. Pusher accepts 10KB and Reverb 10,000 bytes by default.
     * Pass null to stop checking.
     */
    public static function limitBroadcasts(?int $bytes = 10_000): void
    {
        BroadcastLimit::set($bytes);
    }

    /**
     * Handle an oversized broadcast payload instead of throwing BroadcastTooLargeException.
     *
     * @param  (Closure(object, int, int): void)|null  $handler  Receives the event, its size and the limit.
     */
    public static function handleOversizedBroadcastsUsing(?Closure $handler): void
    {
        BroadcastLimit::handleUsing($handler);
    }

    public static function flush(): void
    {
        self::$includeParameter = 'include';
        RenderGuard::flush();
        BroadcastLimit::flush();
    }
}
