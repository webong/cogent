<?php

declare(strict_types=1);

namespace Webong\Cogent\Console;

use Illuminate\Console\Command;
use Webong\Cogent\Resources\PlanExporter;

final class PlansCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'cogent:plans
        {--path=* : Directories to look for resources in, app/Http/Resources by default}
        {--json : Print the plans as JSON}';

    /**
     * @var string
     */
    protected $description = 'List what each preparing resource loads, counts, caches and lets a request include';

    public function handle(PlanExporter $exporter): int
    {
        /** @var list<string> $paths */
        $paths = $this->option('path') ?: [$this->laravel->path('Http/Resources')];

        $plans = array_map($exporter->describe(...), $exporter->discover($paths));

        if ($this->option('json')) {
            $this->line((string) json_encode($plans, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

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
