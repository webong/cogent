<?php

declare(strict_types=1);

namespace Webong\Cogent\Resources;

use Illuminate\Database\Eloquent\Attributes\UseResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use ReflectionClass;
use Webong\Cogent\Attributes\Counts;
use Webong\Cogent\Attributes\Includes;
use Webong\Cogent\Concerns\PreparesResources;

/**
 * What a resource declares it renders: the relations to load and the counts to
 * write before any of its models are transformed.
 */
final class ResourcePlan
{
    /**
     * @var array<class-string, self>
     */
    private static array $plans = [];

    /**
     * @var array<class-string<Model>, class-string<JsonResource>|null>
     */
    private static array $resources = [];

    /**
     * @param  array<string, class-string<JsonResource>|null>  $includes  Relation paths, mapped to the resource that renders them.
     * @param  list<string>  $counts
     */
    public function __construct(
        public readonly array $includes = [],
        public readonly array $counts = [],
        public readonly bool $hasHook = false,
    ) {
    }

    /**
     * Read the plan a resource declares with #[Includes], #[Counts] and a static prepare() method.
     *
     * @param  class-string  $resource
     */
    public static function for(string $resource): self
    {
        return self::$plans[$resource] ??= self::read($resource);
    }

    /**
     * Find the resource that renders a model, the way Laravel's toResource() does:
     * #[UseResource] on the model first, then the App\Http\Resources naming convention.
     *
     * Only resources that prepare themselves are returned, since a plan is all a
     * caller can use them for.
     *
     * @param  class-string<Model>  $model
     * @return class-string<JsonResource>|null
     */
    public static function resourceFor(string $model): ?string
    {
        if (array_key_exists($model, self::$resources)) {
            return self::$resources[$model];
        }

        $candidates = [];

        if (class_exists(UseResource::class)) {
            $attribute = (new ReflectionClass($model))->getAttributes(UseResource::class)[0] ?? null;

            if ($attribute !== null) {
                $candidates[] = $attribute->newInstance()->class;
            }
        }

        // guessResourceName() arrived with toResource() in Laravel 12.
        /** @phpstan-ignore function.alreadyNarrowedType */
        if (method_exists($model, 'guessResourceName')) {
            array_push($candidates, ...$model::guessResourceName());
        }

        foreach ($candidates as $candidate) {
            if (class_exists($candidate) && self::prepares($candidate)) {
                return self::$resources[$model] = $candidate;
            }
        }

        return self::$resources[$model] = null;
    }

    /**
     * @param  class-string  $resource
     */
    public static function prepares(string $resource): bool
    {
        return in_array(PreparesResources::class, class_uses_recursive($resource), true);
    }

    public static function flush(): void
    {
        self::$plans = [];
        self::$resources = [];
    }

    public function isEmpty(): bool
    {
        return $this->includes === [] && $this->counts === [] && ! $this->hasHook;
    }

    /**
     * @param  class-string  $resource
     */
    private static function read(string $resource): self
    {
        $reflection = new ReflectionClass($resource);
        $includes = [];
        $counts = [];

        foreach ($reflection->getAttributes(Includes::class) as $attribute) {
            foreach ($attribute->newInstance()->relations as $relation => $renderedBy) {
                $includes[$relation] = $renderedBy ?? $includes[$relation] ?? null;
            }
        }

        foreach ($reflection->getAttributes(Counts::class) as $attribute) {
            array_push($counts, ...$attribute->newInstance()->relations);
        }

        $hasHook = $reflection->hasMethod('prepare') && $reflection->getMethod('prepare')->isStatic();

        return new self($includes, array_values(array_unique($counts)), $hasHook);
    }
}
