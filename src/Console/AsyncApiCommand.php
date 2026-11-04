<?php

declare(strict_types=1);

namespace Webong\Cogent\Console;

use Illuminate\Console\Command;
use Webong\Cogent\Documentation\AsyncApiExporter;
use Webong\Cogent\Documentation\ResourceSchemaInferer;

final class AsyncApiCommand extends Command
{
    /** @var string */
    protected $signature = 'cogent:asyncapi
        {--events-path=* : Directories to look for broadcast events in, app/Events by default}
        {--models-path=* : Directories to look for broadcast models in, app/Models by default}
        {--title=Cogent broadcasts : API title}';

    /** @var string */
    protected $description = 'Describe channels, event names and resource payloads for Cogent broadcasts as AsyncAPI';

    public function handle(AsyncApiExporter $exporter): int
    {
        if (! ResourceSchemaInferer::available()) {
            $this->components->error('Install dedoc/scramble to infer resource schemas: composer require --dev dedoc/scramble');

            return self::FAILURE;
        }

        /** @var list<string> $events */
        $events = $this->option('events-path') ?: [$this->laravel->path('Events')];
        /** @var list<string> $models */
        $models = $this->option('models-path') ?: [$this->laravel->path('Models')];

        $this->line((string) json_encode(
            $exporter->export($events, $models, $this->option('title')),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }
}
