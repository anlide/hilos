<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Socket\SocketException;

/**
 * ConnectionDropper - master-side seam that force-closes a live WebSocket connection.
 *
 * The sockets live in the master process, so a CLI request to simulate a dropped
 * connection cannot close one itself: it reaches the master over the command channel,
 * and the master hands the request to the daemon that owns the WebSocket clients. The
 * {@see DaemonManager} implements this and is wired into the {@see CommandServer} when the
 * server is registered, so a command handler can drop a connection without depending on
 * the concrete manager.
 *
 * Test-only: the sole caller is the `connection:test:drop` command, used by e2e to exercise
 * the reconnect indicator and the orphan-reconcile that a real socket death triggers.
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
