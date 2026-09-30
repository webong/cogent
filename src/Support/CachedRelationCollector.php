<?php

declare(strict_types=1);

namespace Webong\Cogent\Support;

/**
 * Carries the overrides a relation method passed to cached() from the moment the
 * relation is built until the registry reads them back.
 */
final class CachedRelationCollector
{
    /**
     * @var list<CachedRelationOverrides>
     */
    private static array $pending = [];

    public static function push(CachedRelationOverrides $overrides): void
    {
        self::$pending[] = $overrides;
    }

    public static function depth(): int
    {
        return count(self::$pending);
    }

    /**
     * Take the overrides pushed since the given depth, oldest first.
     *
     * @return CachedRelationOverrides|null
     */
    public static function drain(int $depth): ?CachedRelationOverrides
    {
        $pushed = array_splice(self::$pending, $depth);

        if ($pushed === []) {
            return null;
        }

        $merged = null;

        foreach ($pushed as $overrides) {
            $merged = $overrides->merge($merged);
        }

        return $merged;
    }

    public static function flush(): void
    {
        self::$pending = [];
    }
}
