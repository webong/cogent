<?php

declare(strict_types=1);

namespace Webong\Cogent\Broadcasting;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\BroadcastableModelEventOccurred;
use Illuminate\Database\Eloquent\Model;
use Throwable;
use Webong\Cogent\Resources\ResourcePlan;
use Webong\Cogent\Resources\ResourcePreparer;

/**
 * Holds model broadcasts back while a callback runs, then prepares every model
 * they render in one pass per class and sends each one as a snapshot.
 */
final class BroadcastBatch
{
    private static int $depth = 0;

    /**
     * @var list<BroadcastableModelEventOccurred>
     */
    private static array $pending = [];

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function run(Closure $callback): mixed
    {
        self::$depth++;

        try {
            $result = $callback();
        } catch (Throwable $exception) {
            if (--self::$depth === 0) {
                self::$pending = [];
            }

            throw $exception;
        }

        if (--self::$depth === 0) {
            self::flush();
        }

        return $result;
    }

    /**
     * Hold a model broadcast back, when a batch is running and the model has a resource to render.
     */
    public static function defer(BroadcastableModelEventOccurred $event): bool
    {
        if (self::$depth === 0 || ResourcePlan::guessResource($event->model::class) === null) {
            return false;
        }

        self::$pending[] = $event;

        return true;
    }

    private static function flush(): void
    {
        [$events, self::$pending] = [self::$pending, []];

        $container = Container::getInstance();
        $preparer = $container->make(ResourcePreparer::class);
        $request = $container->make('request');

        /** @var array<class-string<Model>, list<Model>> $models */
        $models = [];

        foreach ($events as $event) {
            $models[$event->model::class][] = $event->model;
        }

        foreach ($models as $class => $group) {
            $resource = ResourcePlan::resourceFor($class);

            if ($resource !== null) {
                $preparer->prepare($group, $resource, $request, [], []);
            }
        }

        foreach ($events as $event) {
            broadcast(ModelBroadcastSnapshot::of($event, $event->broadcastWith() ?? []));
        }
    }
}
