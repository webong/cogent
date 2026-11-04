<?php

declare(strict_types=1);

namespace Webong\Cogent\Resources;

use Closure;
use Webong\Cogent\Exceptions\BroadcastTooLargeException;

/**
 * Checks the size of payloads built by BroadcastsResources and BroadcastsAsResource
 * against what the broadcasting driver accepts.
 */
final class BroadcastLimit
{
    private static ?int $bytes = null;

    /**
     * @var (Closure(object, int, int): void)|null
     */
    private static ?Closure $handler = null;

    public static function set(?int $bytes): void
    {
        self::$bytes = $bytes;
    }

    /**
     * @param  (Closure(object, int, int): void)|null  $handler
     */
    public static function handleUsing(?Closure $handler): void
    {
        self::$handler = $handler;
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    public static function check(object $event, array $payload): array
    {
        if (self::$bytes === null) {
            return $payload;
        }

        $bytes = mb_strlen(json_encode($payload, JSON_THROW_ON_ERROR));

        if ($bytes <= self::$bytes) {
            return $payload;
        }

        if (self::$handler !== null) {
            (self::$handler)($event, $bytes, self::$bytes);

            return $payload;
        }

        throw new BroadcastTooLargeException($event, $bytes, self::$bytes);
    }

    public static function flush(): void
    {
        self::$bytes = null;
        self::$handler = null;
    }
}
