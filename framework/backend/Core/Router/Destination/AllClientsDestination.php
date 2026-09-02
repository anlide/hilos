<?php

declare(strict_types=1);

namespace Hilos\Core\Router\Destination;

/**
 * AllClientsDestination - Broadcasts a signal to every connected WebSocket client.
 *
 * Unlike WebSocketDestination, which targets one client by accept key, the daemon
 * delivers this in a single pass over all connected clients, optionally excluding
 * one accept key and one browser session. Used for the rare ws_all_connected
 * broadcast; routine fan-out to page subscribers stays a list of
 * WebSocketDestination.
 *
 * The two exclusions are independent and both are kept, because they answer different
 * questions: the accept key names the socket that asked, and the session names the person
 * behind it - whose other tabs a broadcast about their own operation must not raise.
 */
final class AllClientsDestination implements Destination
{
    /**
     * @param ?string $excludeAcceptKey Accept key to exclude from the broadcast, or null to send to all
     * @param ?string $excludeSessionTokenHash Hash of the session token whose connections are excluded,
     *                                         or null to leave no browser out
     */
    public function __construct(
        public readonly ?string $excludeAcceptKey = null,
        public readonly ?string $excludeSessionTokenHash = null,
    ) {
    }
}
