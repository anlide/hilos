<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode;

/**
 * Local port the daemon uses to relay the leader's ready to the initiator agent on this node.
 *
 * The final hop of the two-phase freeze: once {@see ClusterProtectedMode} learns every node has
 * quiesced, {@see DaemonProtectedModeExecutor::notifyInitiatorReady()} hands the initiator's
 * agent identity to this seam so the daemon can address the worker hosting it. The worker server
 * implements it by reusing its send-to-agent-worker path, mirroring how
 * {@see \Hilos\Cluster\Placement\PlacementExecutor} and {@see \Hilos\Cluster\AgentSignalSink}
 * expose the worker server to the peer transport. A test supplies a fake so the executor runs
 * without a worker pool.
 */
interface ProtectedModeReadyRelay
{
    /**
     * Relays the ready to the initiator agent's worker; a no-op when the agent is not hosted here.
     *
     * @param string $agentType Initiator agent type
     * @param ?string $agentIndex Initiator agent index, or null for a singleton agent
     */
    public function deliverProtectedModeReady(string $agentType, ?string $agentIndex): void;
}
