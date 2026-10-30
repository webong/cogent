<?php

declare(strict_types=1);

namespace Webong\Cogent\Concerns;

use ReflectionClass;

/**
 * Render the broadcast payload when the event is queued, in the request that
 * already loaded its models, instead of restoring them in the worker.
 *
 * Use it in place of SerializesModels: models are serialized whole, with what
 * was loaded for them, so broadcastOn() still reads them and the worker runs no
 * query. The payload is what the models looked like when the event was dispatched.
 */
trait SnapshotsBroadcasts
{
    use BroadcastsResources {
        broadcastWith as renderBroadcast;
    }

    /**
     * @var array<string, mixed>|null
     */
    private ?array $cogentSnapshot = null;

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->cogentSnapshot ?? $this->renderBroadcast();
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        $values = [];

        foreach ((new ReflectionClass($this))->getProperties() as $property) {
            if ($property->isStatic() || ! $property->isInitialized($this)) {
                continue;
            }

            $values[$property->getName()] = $property->getValue($this);
        }

        $values['cogentSnapshot'] = $this->cogentSnapshot ?? $this->renderBroadcast();

        return $values;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function __unserialize(array $values): void
    {
        $class = new ReflectionClass($this);

        foreach ($values as $name => $value) {
            if ($class->hasProperty($name) && ! $class->getProperty($name)->isStatic()) {
                $class->getProperty($name)->setValue($this, $value);
            }
        }
    }
}
