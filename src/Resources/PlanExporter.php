<?php

declare(strict_types=1);

namespace Webong\Cogent\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Webong\Cogent\Documentation\ClassDiscovery;
use Webong\Cogent\Documentation\ResourceSchemaInferer;

/**
 * Describes what preparing resources declare, for tooling: documentation,
 * API specs and reviews of what a client can ask for.
 */
final class PlanExporter
{
    /**
     * Every class in the given directories that prepares itself.
     *
     * @param  list<string>  $paths
     * @return list<class-string<JsonResource>>
     */
    public function discover(array $paths): array
    {
        return (new ClassDiscovery)->in($paths, static fn (string $class): bool => is_subclass_of($class, JsonResource::class) && ResourcePlan::prepares($class));
    }

    /**
     * @param  class-string  $resource
     * @return array{
     *     resource: class-string,
     *     includes: array<string, class-string<JsonResource>|null>,
     *     includable: array<string, class-string<JsonResource>|null>,
     *     counts: list<string>,
     *     prepare: bool,
     *     remember: array{ttl: int, shared: bool}|null,
     *     requestable: list<string>
     * }
     */
    public function describe(string $resource): array
    {
        $plan = ResourcePlan::for($resource);

        return [
            'resource' => $resource,
            'includes' => $plan->includes,
            'includable' => $plan->includable,
            'counts' => $plan->counts,
            'prepare' => $plan->hasHook,
            'remember' => $plan->remember === null ? null : ['ttl' => $plan->remember->ttl, 'shared' => $plan->remember->shared],
            'requestable' => $this->requestable($resource),
        ];
    }

    /**
     * OpenAPI 3.1 components for the given resources: an include query parameter
     * for each resource a request can include relations on, and each plan as an
     * x-cogent extension. Reference a parameter from an operation with
     * {"$ref": "#/components/parameters/PostResourceInclude"}.
     *
     * @param  list<class-string>  $resources
     * @param  array<string, array{
     *     includes: array<string, class-string|null>,
     *     filters: array<string, string>,
     *     sorts: array<string, string>
     * }>  $queryBuilders  Optional spatie QueryBuilder metadata keyed by resource class name.
     *                      When provided, filter and sort query parameters are added to the OpenAPI output.
     * @return array<string, mixed>
     */
    public function openApi(array $resources, string $parameter = 'include', array $queryBuilders = []): array
    {
        if (! ResourceSchemaInferer::available()) {
            throw new \RuntimeException('Install dedoc/scramble to infer resource schemas: composer require --dev dedoc/scramble');
        }

        $inferred = (new ResourceSchemaInferer)->infer($resources);
        $parameters = [];
        $plans = [];

        foreach ($resources as $resource) {
            $plan = $this->describe($resource);
            $name = class_basename($resource);
            $plans[$name] = $plan + ['schema' => $inferred['references'][$resource]];

            $qbMeta = $queryBuilders[$resource] ?? null;

            if ($plan['requestable'] === [] && ($qbMeta === null || $qbMeta['includes'] === [])) {
                continue;
            }

            $parameters[$name.'Include'] = [
                'name' => $parameter,
                'in' => 'query',
                'required' => false,
                'description' => 'Relations to include, comma separated. Anything else is ignored.',
                'style' => 'form',
                'explode' => false,
                'schema' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'enum' => $plan['requestable']],
                    'uniqueItems' => true,
                ],
            ];

            // spatie QueryBuilder filter parameters
            if ($qbMeta !== null && $qbMeta['filters'] !== []) {
                foreach ($qbMeta['filters'] as $filterName => $filterClass) {
                    $parameters[$name.'Filter'.ucfirst($filterName)] = [
                        'name' => 'filter['.$filterName.']',
                        'in' => 'query',
                        'required' => false,
                        'description' => 'Filter by '.ucfirst($filterName).'.',
                        'style' => 'form',
                        'explode' => false,
                        'schema' => ['type' => 'string'],
                        'x-cogent' => ['filterClass' => $filterClass],
                    ];
                }
            }

            // spatie QueryBuilder sort parameters
            if ($qbMeta !== null && $qbMeta['sorts'] !== []) {
                $parameters[$name.'Sort'] = [
                    'name' => 'sort',
                    'in' => 'query',
                    'required' => false,
                    'description' => 'Sort by comma-separated fields. Prefix with - for descending.',
                    'style' => 'form',
                    'explode' => false,
                    'schema' => [
                        'type' => 'array',
                        'items' => ['type' => 'string', 'enum' => array_keys($qbMeta['sorts'])],
                        'uniqueItems' => true,
                    ],
                    'x-cogent' => ['sorts' => $qbMeta['sorts']],
                ];
            }
        }

        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Cogent resource plans', 'version' => '1.0.0'],
            'paths' => (object) [],
            'components' => ['parameters' => (object) $parameters, 'schemas' => (object) $inferred['schemas']],
            'x-cogent' => ['resources' => (object) $plans],
        ];
    }

    /**
     * Every ?include= path a request may name, through the resources that render
     * each relation. Nested resources are followed when a plan maps them.
     *
     * @param  class-string  $resource
     * @param  list<class-string>  $chain
     * @return list<string>
     */
    public function requestable(string $resource, array $chain = []): array
    {
        if (in_array($resource, $chain, true)) {
            return [];
        }

        $chain[] = $resource;
        $plan = ResourcePlan::for($resource);
        $paths = [];

        foreach (array_keys($plan->includable) as $path) {
            $segments = explode('.', $path);

            foreach (array_keys($segments) as $index) {
                $paths[] = implode('.', array_slice($segments, 0, $index + 1));
            }
        }

        foreach ([...$plan->includes, ...$plan->includable] as $path => $renderedBy) {
            if ($renderedBy === null || ! ResourcePlan::prepares($renderedBy)) {
                continue;
            }

            foreach ($this->requestable($renderedBy, $chain) as $nested) {
                $paths[] = $path.'.'.$nested;
            }
        }

        $paths = array_values(array_unique($paths));
        sort($paths);

        return $paths;
    }

}
