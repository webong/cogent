<?php

declare(strict_types=1);

namespace Webong\Cogent\QueryBuilder;

use Illuminate\Support\Collection;
use ReflectionClass;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Reads a spatie QueryBuilder's declared filters, sorts, and includes
 * so Cogent can document them in OpenAPI output and keep them in sync
 * with #[Includable] declarations.
 *
 * spatie/laravel-query-builder stores these as protected properties on
 * its traits, so we read them via reflection.
 */
final class QueryBuilderMetadata
{
    /**
     * @param  \Spatie\QueryBuilder\QueryBuilder  $queryBuilder
     * @return array{
     *     includes: array<string, class-string|null>,
     *     filters: array<string, string>,
     *     sorts: array<string, string>
     * }
     */
    public static function from(QueryBuilder $queryBuilder): array
    {
        $reflection = new ReflectionClass($queryBuilder);

        return [
            'includes' => self::allowedIncludes($reflection, $queryBuilder),
            'filters' => self::allowedFilters($reflection, $queryBuilder),
            'sorts' => self::allowedSorts($reflection, $queryBuilder),
        ];
    }

    /**
     * @return array<string, class-string|null>
     */
    private static function allowedIncludes(ReflectionClass $reflection, object $queryBuilder): array
    {
        $property = $reflection->getProperty('allowedIncludes');
        $property->setAccessible(true);

        if (! $property->isInitialized($queryBuilder)) {
            return [];
        }

        $includes = $property->getValue($queryBuilder);

        if (! $includes instanceof Collection) {
            return [];
        }

        $result = [];

        foreach ($includes as $allowedInclude) {
            $result[$allowedInclude->getName()] = null;
        }

        return $result;
    }

    /**
     * @return array<string, string>
     */
    private static function allowedFilters(ReflectionClass $reflection, object $queryBuilder): array
    {
        $property = $reflection->getProperty('allowedFilters');
        $property->setAccessible(true);

        if (! $property->isInitialized($queryBuilder)) {
            return [];
        }

        $filters = $property->getValue($queryBuilder);

        if (! $filters instanceof Collection) {
            return [];
        }

        $result = [];

        foreach ($filters as $allowedFilter) {
            $result[$allowedFilter->getName()] = get_class($allowedFilter->getFilterClass());
        }

        return $result;
    }

    /**
     * @return array<string, string>
     */
    private static function allowedSorts(ReflectionClass $reflection, object $queryBuilder): array
    {
        $property = $reflection->getProperty('allowedSorts');
        $property->setAccessible(true);

        if (! $property->isInitialized($queryBuilder)) {
            return [];
        }

        $sorts = $property->getValue($queryBuilder);

        if (! $sorts instanceof Collection) {
            return [];
        }

        $result = [];

        foreach ($sorts as $allowedSort) {
            $direction = $allowedSort->getDefaultDirection()->value;
            $result[$allowedSort->getName()] = $direction;
        }

        return $result;
    }
}
