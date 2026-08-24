<?php

declare(strict_types=1);

namespace Hilos\Core\Router\Destination;

use Hilos\Cluster\Peer\DTO\PeerClientSignalDTO;
use Hilos\Core\Daemon\DaemonManager;

/**
 * RemoteClientDestination - Routes a signal to a browser attached to another cluster node.
 *
 * The browser-side twin of {@see RemoteAgentDestination}. The router contributes this in
 * place of a {@see WebSocketDestination} when the connection lookup reports the accept key
 * is held by a different node: it carries the same local target (the accept key) plus the id
 * of the node holding it. {@see DaemonManager} forwards it over the peer channel to that
 * node, which delivers it to the socket exactly as it delivers one of its own.
 *
 * A signal to a browser attached here stays a {@see WebSocketDestination}; only a cross-node
 * one becomes this. Like every {@see Destination} it is computed and consumed inside the
 * daemon and never itself crosses a process boundary — the peer frame
 * ({@see PeerClientSignalDTO}) is what travels the wire.
 */
final class RemoteClientDestination implements Destination
{
    /**
     * @param string $nodeId Id of the node holding the target connection
     * @param string $acceptKey Target client accept key
     */
    public function __construct(
        public readonly string $nodeId,
        public readonly string $acceptKey,
    ) {
    }
}
