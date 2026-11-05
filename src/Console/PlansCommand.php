<?php

declare(strict_types=1);

namespace Webong\Cogent\Console;

use Illuminate\Console\Command;
use Webong\Cogent\Cogent;
use Webong\Cogent\Documentation\AsyncApiExporter;
use Webong\Cogent\Documentation\ResourceSchemaInferer;
use Webong\Cogent\Resources\PlanExporter;

/**
 * Describe Cogent resources, plans, and broadcasts for documentation.
 *
 * Output formats:
 *   table   (default)  human-readable table of resource plans
 *   json      JSON plans for every preparing resource
 *   openapi   OpenAPI 3.1 components: include parameters + resource schemas
 *   asyncapi  AsyncAPI 3.0 document: broadcast channels, events, payloads
 */
final class PlansCommand extends Command
{
    protected $signature = 'cogent:plans
        {--path=* : Directories to look for resources in, app/Http/Resources by default}
        {--events-path=* : Directories to look for broadcast events in, app/Events by default}
        {--models-path=* : Directories to look for broadcast models in, app/Models by default}
        {--format=table : Output format: table, json, openapi, or asyncapi}
        {--title=Cogent : API/broadcast title}';

    protected $description = 'Describe Cogent resources, plans, and broadcasts for documentation';

    public function handle(PlanExporter $planExporter, AsyncApiExporter $asyncApiExporter): int
    {
        return match ($this->option('format')) {
            'asyncapi' => $this->handleAsyncApi($asyncApiExporter),
            'openapi' => $this->handleOpenApi($planExporter),
            'json' => $this->handleJson($planExporter),
            default => $this->handleTable($planExporter),
        };
    }

    private function handleAsyncApi(AsyncApiExporter $exporter): int
    {
        if (! ResourceSchemaInferer::available()) {
            $this->components->error('Install dedoc/scramble to infer resource schemas: composer require --dev dedoc/scramble');

            return self::FAILURE;
        }

        $events = $this->option('events-path') ?: [$this->laravel->path('Events')];
        $models = $this->option('models-path') ?: [$this->laravel->path('Models')];

        $this->line((string) json_encode(
            $exporter->export($events, $models, $this->option('title')),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }

    private function handleOpenApi(PlanExporter $exporter): int
    {
        if (! ResourceSchemaInferer::available()) {
            $this->components->error('Install dedoc/scramble to infer resource schemas: composer require --dev dedoc/scramble');

            return self::FAILURE;
        }

        $paths = $this->option('path') ?: [$this->laravel->path('Http/Resources')];
        $resources = array_map($exporter->describe(...), $exporter->discover($paths));

        $this->line((string) json_encode(
            $exporter->openApi(array_column($resources, 'resource'), Cogent::includeParameterName()),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }

    private function handleJson(PlanExporter $exporter): int
    {
        $paths = $this->option('path') ?: [$this->laravel->path('Http/Resources')];
        $plans = array_map($exporter->describe(...), $exporter->discover($paths));

        $this->line((string) json_encode($plans, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    private function handleTable(PlanExporter $exporter): int
    {
        $paths = $this->option('path') ?: [$this->laravel->path('Http/Resources')];
        $plans = array_map($exporter->describe(...), $exporter->discover($paths));

        if ($plans === []) {
            $this->components->info('No resources use PreparesResources.');

            return self::SUCCESS;
        }

        $this->table(
            ['Resource', 'Includes', 'Counts', '?include=', 'Remember'],
            array_map(static fn (array $plan): array => [
                $plan['resource'],
                implode(', ', array_keys($plan['includes'])),
                implode(', ', $plan['counts']),
                implode(', ', $plan['requestable']),
                $plan['remember'] === null ? '' : $plan['remember']['ttl'].'s'.($plan['remember']['shared'] ? ', shared' : ''),
            ], $plans),
        );

        return self::SUCCESS;
    }
}
