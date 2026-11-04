<?php

declare(strict_types=1);

namespace Webong\Cogent\Broadcasting;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Database\Eloquent\BroadcastableModelEventOccurred;

/**
 * A model event rendered when it was dispatched: its channels, name and payload,
 * without the model, so the worker that broadcasts it runs no query.
 */
final class ModelBroadcastSnapshot implements ShouldBroadcast
{
    /**
     * @param  array<int, Channel|string>  $channels
     * @param  array<mixed>  $payload
     */
    public function __construct(
        public readonly array $channels,
        public readonly string $name,
        public readonly array $payload,
        public readonly bool $now,
        public ?string $connection = null,
        public ?string $queue = null,
        public ?bool $afterCommit = null,
        public ?string $socket = null,
    ) {
    }

    /**
     * @param  array<mixed>  $payload
     */
    public static function of(BroadcastableModelEventOccurred $event, array $payload): self
    {
        $socket = $event->socket ?? null;

        return new self(
            channels: $event->broadcastOn(),
            name: $event->broadcastAs(),
            payload: $payload,
            now: $event->shouldBroadcastNow(),
            connection: $event->connection,
            queue: $event->queue,
            afterCommit: $event->afterCommit,
            socket: is_string($socket) ? $socket : null,
        );
    }

    /**
     * @return array<int, Channel|string>
     */
    public function broadcastOn(): array
    {
        return $this->channels;
    }

    public function broadcastAs(): string
    {
        return $this->name;
    }

    /**
     * @return array<mixed>
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }

    public function shouldBroadcastNow(): bool
    {
        return $this->now;
    }
}
