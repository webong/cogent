<?php

declare(strict_types=1);

namespace Webong\Cogent\Resources;

use FilesystemIterator;
use Illuminate\Http\Resources\Json\JsonResource;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

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
        $resources = [];

        foreach ($paths as $path) {
            if (! is_dir($path)) {
                continue;
            }

            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));

            /** @var SplFileInfo $file */
            foreach ($files as $file) {
                $class = $file->getExtension() === 'php' ? $this->classIn($file->getPathname()) : null;

                if ($class !== null && class_exists($class) && is_subclass_of($class, JsonResource::class) && ResourcePlan::prepares($class)) {
                    $resources[] = $class;
                }
            }
        }

        sort($resources);

        return array_values(array_unique($resources));
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

    /**
     * Read the class a file declares from its namespace and class statements.
     */
    private function classIn(string $file): ?string
    {
        $tokens = token_get_all((string) file_get_contents($file));
        $namespace = '';

        foreach ($tokens as $index => $token) {
            if (! is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $namespace = $this->nextName($tokens, $index);
            }

            if ($token[0] === T_CLASS && ! $this->isClassConstant($tokens, $index)) {
                $name = $this->nextName($tokens, $index);

                return $name === '' ? null : mb_ltrim($namespace.'\\'.$name, '\\');
            }
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $tokens
     */
    private function nextName(array $tokens, int $index): string
    {
        $count = count($tokens);

        for ($next = $index + 1; $next < $count; $next++) {
            $token = $tokens[$next];

            if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED], true)) {
                return $token[1];
            }

            if ($token === ';' || $token === '{' || $token === '(') {
                return '';
            }
        }

        return '';
    }

    /**
     * @param  array<int, mixed>  $tokens
     */
    private function isClassConstant(array $tokens, int $index): bool
    {
        for ($previous = $index - 1; $previous >= 0; $previous--) {
            $token = $tokens[$previous];

            if (is_array($token) && $token[0] === T_WHITESPACE) {
                continue;
            }

            return (is_array($token) && $token[0] === T_DOUBLE_COLON) || (is_array($token) && $token[0] === T_NEW);
        }

        return false;
    }
}
