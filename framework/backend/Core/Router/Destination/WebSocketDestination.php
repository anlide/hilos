<?php

declare(strict_types=1);

namespace Hilos\Core\Router\Destination;

/**
 * WebSocketDestination - Routes a signal to a single WebSocket client by accept key.
 */
final class WebSocketDestination implements Destination
{
    /**
     * @param string $acceptKey Target client accept key
     */
    public function __construct(
        public readonly string $acceptKey,
    ) {
    }
}
