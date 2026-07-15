<?php

declare(strict_types=1);

namespace Hilos\Core\Router\Destination;

use Hilos\Cluster\Peer\DTO\PeerSignalDTO;
use Hilos\Core\Daemon\DaemonManager;

/**
 * RemoteAgentDestination - Routes a signal to an agent living on another cluster node.
 *
 * The router contributes this in place of an {@see AgentDestination} when the
 * placement lookup reports the target agent is hosted on a different node: it
 * carries the same local target (agent type and optional index) plus the id of the
 * node that hosts it. {@see DaemonManager} forwards it over the
 * peer channel to that node, which resolves the local target and delivers directly.
 *
 * A signal to a local agent stays an {@see AgentDestination}; only a cross-node
 * target becomes this. Like every {@see Destination} it is computed and consumed
 * inside the daemon and never itself crosses a process boundary — the peer frame
 * ({@see PeerSignalDTO}) is what travels the wire.
 */
final class RemoteAgentDestination implements Destination
{
    /**
     * @param string $nodeId Id of the node hosting the target agent
     * @param string $agentType Target agent type
     * @param ?string $agentIndex Agent instance index, or null for unindexed agents
     */
    public function __construct(
        public readonly string $nodeId,
        public readonly string $agentType,
        public readonly ?string $agentIndex = null,
    ) {
    }
}
