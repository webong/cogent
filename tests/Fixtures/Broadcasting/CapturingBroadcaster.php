<?php

declare(strict_types=1);

namespace Webong\Cogent\Tests\Fixtures\Broadcasting;

use Illuminate\Broadcasting\Broadcasters\Broadcaster;

final class CapturingBroadcaster extends Broadcaster
{
    /**
     * @var list<array{channels: list<string>, event: string, payload: array<string, mixed>}>
     */
    public array $broadcasts = [];

    public static ?self $last = null;

    public function __construct()
    {
        self::$last = $this;
    }

    public function auth($request): mixed
    {
        return null;
    }

    public function validAuthenticationResponse($request, $result): mixed
    {
        return null;
    }

    /**
     * @param  array<int, mixed>  $channels
     * @param  string  $event
     * @param  array<string, mixed>  $payload
     */
    public function broadcast(array $channels, $event, array $payload = []): void
    {
        $this->broadcasts[] = [
            'channels' => array_map(strval(...), $channels),
            'event' => $event,
            'payload' => $payload,
        ];
    }
}
