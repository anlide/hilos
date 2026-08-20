<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Socket\Client\WebSocketClient;
use Hilos\Socket\Server\CommandServer;
use Hilos\Socket\Server\WebSocketServer;
use Hilos\Socket\SocketException;

/**
 * ConnectionDropper - master-side seam that force-closes a live WebSocket connection.
 *
 * The sockets live in the master process and no one holds them but the daemon, so anything
 * that has to close a connection it is not itself serving asks through here. The
 * {@see DaemonManager} implements this and is wired into both the {@see CommandServer} and
 * the {@see WebSocketServer} when the server is registered, so neither a command handler nor
 * an accepted client depends on the concrete manager.
 *
 * Two callers today, one of them production. A handshake that trades a session-rotation
 * ticket (HIL-582) drops the other connections of the session it just moved, after the new
 * cookie is on the wire: they reconnect carrying it and land back in their own session,
 * where dropping them any earlier would have sent them into a fresh anonymous one. The
 * other is the `test:connection:drop` command, used by e2e to exercise the reconnect
 * indicator and the orphan-reconcile that a real socket death triggers.
 */
interface ConnectionDropper
{
    /**
     * Force-closes the live WebSocket connection whose acceptKey matches, if any.
     *
     * Closing the socket runs the normal disconnect path ({@see WebSocketClient::onClose()}),
     * so presence decrements, subscriptions are dropped, and the client sees the socket die
     * and reconnects - exactly as an unplanned drop would.
     *
     * @param string $acceptKey Daemon-minted identifier of the connection to close
     * @return bool True when a matching live connection was found and closed, false otherwise
     * @throws SocketException When closing the matched connection's socket fails
     */
    public function dropWebSocketConnection(string $acceptKey): bool;
}
