<?php

declare(strict_types=1);

namespace Webong\Cogent\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase as Orchestra;
use Webong\Cogent\CogentServiceProvider;

use function Orchestra\Testbench\default_skeleton_path;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    /**
     * Pin the application base path to the Testbench skeleton.
     *
     * Laravel skeletons often export APP_BASE_PATH, which would otherwise make
     * Testbench boot the host application instead of an isolated one.
     */
    public static function applicationBasePath(): string
    {
        return (string) default_skeleton_path();
    }

    /**
     * @param  \Illuminate\Foundation\Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [CogentServiceProvider::class];
    }

    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('database.default', 'testing');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        DB::connection()->getPdo()->sqliteCreateFunction(
            'LEAST',
            static fn (int|float ...$values): int|float => min($values),
        );
    }

    /**
     * Count every query executed from this point on.
     *
     * @return \Closure(): int
     */
    protected function countQueries(): \Closure
    {
        $counter = new class {
            public int $count = 0;
        };

        DB::listen(static function () use ($counter): void {
            $counter->count++;
        });

        return static fn (): int => $counter->count;
    }
}
