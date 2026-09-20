<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode;

use Hilos\Cluster\Placement\PlacementExecutor;

/**
 * Local port the daemon uses to relay the leader's ready or refusal to the initiator agent on this node.
 *
 * The final hop of the two-phase freeze: once {@see ClusterProtectedMode} learns every node has
 * quiesced, {@see DaemonProtectedModeExecutor::notifyInitiatorReady()} hands the initiator's
 * agent identity to this seam so the daemon can address the worker hosting it. If refused,
 * refusal is delivered over the same seam. The worker server implements it by reusing its
 * send-to-agent-worker path, mirroring how {@see PlacementExecutor} exposes the worker server
 * to the peer transport. A test supplies a fake so the executor runs without a worker pool.
 */
interface ProtectedModeInitiatorRelay
{
    /**
     * Relays the ready to the initiator agent's worker; a no-op when the agent is not hosted here.
     *
     * @param string $agentType Initiator agent type
     * @param ?string $agentIndex Initiator agent index, or null for a singleton agent
     */
    public function deliverProtectedModeReady(string $agentType, ?string $agentIndex): void;

    /**
     * Relays the refusal to the initiator agent's worker; a no-op when the agent is not hosted here.
     *
     * @param string $agentType Initiator agent type
     * @param ?string $agentIndex Initiator agent index, or null for a singleton agent
     * @param string $reason Human-readable operator-facing refusal message
     */
    public function deliverProtectedModeRefused(string $agentType, ?string $agentIndex, string $reason): void;
}
