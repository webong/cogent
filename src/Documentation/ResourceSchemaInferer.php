<?php

declare(strict_types=1);

namespace Webong\Cogent\Documentation;

use Dedoc\Scramble\GeneratorConfig;
use Dedoc\Scramble\Infer;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\ScrambleServiceProvider;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\TypeTransformer;
use Dedoc\Scramble\Support\Type\ObjectType;
use Illuminate\Container\Container;

/** Infers resource schemas from toArray() with Scramble, when it is installed. */
final class ResourceSchemaInferer
{
    private ?TypeTransformer $transformer = null;

    public static function available(): bool
    {
        return class_exists(ScrambleServiceProvider::class);
    }

    /**
     * @param  list<class-string>  $resources
     * @return array{schemas: array<string, mixed>, references: array<class-string, string>}
     */
    public function infer(array $resources): array
    {
        $transformer = $this->transformer();
        $references = [];

        foreach ($resources as $resource) {
            $schema = $transformer->transform(new ObjectType($resource));
            $references[$resource] = $schema->toArray()['$ref'];
        }

        /** @var array<string, mixed> $schemas */
        $schemas = json_decode(json_encode(
            $transformer->getComponents()->toArray()['schemas'] ?? [],
            JSON_THROW_ON_ERROR,
        ), true, flags: JSON_THROW_ON_ERROR);

        return ['schemas' => $schemas, 'references' => $references];
    }

    private function transformer(): TypeTransformer
    {
        if ($this->transformer !== null) {
            return $this->transformer;
        }

        /** @var \Illuminate\Contracts\Foundation\Application $app */
        $app = Container::getInstance();

        if (! $app->bound(Infer::class)) {
            (new ScrambleServiceProvider($app))->register();
        }

        return $this->transformer = $app->make(TypeTransformer::class, [
            'context' => new OpenApiContext(new OpenApi, new GeneratorConfig),
        ]);
    }
}
