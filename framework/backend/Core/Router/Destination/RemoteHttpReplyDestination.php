<?php

declare(strict_types=1);

namespace Hilos\Core\Router\Destination;

use Hilos\Cluster\Peer\DTO\PeerHttpReplyDTO;
use Hilos\Core\Daemon\DaemonManager;

/**
 * RemoteHttpReplyDestination - Routes an agent's HTTP reply to the cluster node holding the connection.
 *
 * The HTTP twin of {@see RemoteClientDestination}: the agent answered on this node, the browser's
 * connection is parked on another. {@see DaemonManager} forwards the reply over the peer channel
 * ({@see PeerHttpReplyDTO}), and that node writes it to its own held connection without routing it
 * again. Computed and consumed inside the daemon, like every {@see Destination}.
 */
final class RemoteHttpReplyDestination implements Destination
{
    /**
     * @param string $nodeId Id of the node holding the parked connection
     * @param string $correlationId Correlation id of the parked HTTP request
     */
    public function __construct(
        public readonly string $nodeId,
        public readonly string $correlationId,
    ) {
    }
}
