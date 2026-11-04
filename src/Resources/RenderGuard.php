<?php

declare(strict_types=1);

namespace Webong\Cogent\Resources;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Webong\Cogent\Exceptions\QueryWhileRenderingException;

/**
 * Watches for queries while a prepared resource renders. Everything a resource
 * renders is loaded before it renders, so any query in between is a lazy load
 * or a query in an accessor that the plan missed.
 */
final class RenderGuard
{
    private static bool $enabled = false;

    /**
     * @var (Closure(class-string, QueryExecuted): void)|null
     */
    private static ?Closure $handler = null;

    /**
     * The resources rendering right now, innermost last.
     *
     * @var list<class-string>
     */
    private static array $rendering = [];

    public static function enable(bool $value = true): void
    {
        self::$enabled = $value;
    }

    /**
     * @param  (Closure(class-string, QueryExecuted): void)|null  $handler
     */
    public static function handleUsing(?Closure $handler): void
    {
        self::$handler = $handler;
    }

    public static function enabled(): bool
    {
        return self::$enabled;
    }

    /**
     * Render inside the guard. Nested resources render while the result is
     * encoded, so a guarded render is encoded before the guard is lifted.
     *
     * @param  class-string  $resource
     * @param  Closure(): array<mixed>  $render
     * @return array<mixed>
     */
    public static function watch(string $resource, Closure $render): array
    {
        if (! self::$enabled) {
            return $render();
        }

        self::$rendering[] = $resource;

        try {
            return ResourcePayload::encode($render());
        } finally {
            array_pop(self::$rendering);
        }
    }

    public static function queried(QueryExecuted $query): void
    {
        $resource = self::$rendering[array_key_last(self::$rendering) ?? -1] ?? null;

        if ($resource === null) {
            return;
        }

        if (self::$handler !== null) {
            (self::$handler)($resource, $query);

            return;
        }

        throw new QueryWhileRenderingException($resource, $query);
    }

    public static function flush(): void
    {
        self::$enabled = false;
        self::$handler = null;
        self::$rendering = [];
    }
}
