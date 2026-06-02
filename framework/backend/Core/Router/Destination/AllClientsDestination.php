<?php

declare(strict_types=1);

namespace Hilos\Core\Router\Destination;

/**
 * AllClientsDestination - Broadcasts a signal to every connected WebSocket client.
 *
 * Unlike WebSocketDestination, which targets one client by accept key, the daemon
 * delivers this in a single pass over all connected clients, optionally excluding
 * one accept key. Used for the rare ws_all_connected broadcast; routine fan-out to
 * page subscribers stays a list of WebSocketDestination.
 */
final class AllClientsDestination implements Destination
{
    /**
     * @param ?string $excludeAcceptKey Accept key to exclude from the broadcast, or null to send to all
     */
    public function __construct(
        public readonly ?string $excludeAcceptKey = null,
    ) {
    }
}
