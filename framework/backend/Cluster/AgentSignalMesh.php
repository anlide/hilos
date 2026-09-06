<?php

declare(strict_types=1);

namespace Hilos\Cluster;

use Hilos\Cluster\Peer\PeerServer;
use Hilos\Core\Router\DTO\SignalDTO;

/**
 * Outbound peer port the daemon hands a signal for an agent on another node through.
 *
 * The mirror of {@see AgentSignalSink}: that one carries a resolved signal into this node,
 * this one carries a resolved signal out of it. It hides the {@see PeerServer} behind the
 * single send the delivery path needs, for the reason every mesh port in this framework
 * exists — the sending side stays logic a test can drive with a fake instead of a listener
 * and a live link.
 *
 * Addressed and never broadcast, unlike {@see RtSyncMesh}: the placement lookup has already
 * named the one node hosting the agent, so there is nobody else to tell.
 */
interface AgentSignalMesh
{
    /**
     * Forwards one resolved signal to an agent running on another node.
     *
     * Best-effort: a false answer is not an error but the caller's cue to drop and log, the
     * same contract {@see ClientMesh::sendSignalToClientNode()} has. Buffering and retry on an
     * offline node are out of scope.
     *
     * @param string $targetNodeId Id of the node hosting the target agent
     * @param string $agentType Resolved target agent type
     * @param ?string $agentIndex Resolved target agent index, or null for a singleton agent
     * @param SignalDTO $signal Signal to deliver on the target node
     * @return bool True when a live link carried the frame, false when the node is unlinked
     */
    public function sendSignalToNode(string $targetNodeId, string $agentType, ?string $agentIndex, SignalDTO $signal): bool;
}
