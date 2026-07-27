<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode;

/**
 * Local port the daemon uses to stop this node's application agents while protected mode holds.
 *
 * The graceful-shutdown half of the freeze: once {@see ClusterProtectedMode} decides this node
 * enters the freeze, {@see DaemonProtectedModeExecutor::enterActivating()} hands the initiator's
 * agent identity to this seam so the daemon can stop every agent it hosts except the one driving
 * the destructive operation. The worker server implements it by reusing its per-agent stop path,
 * mirroring how {@see ProtectedModeReadyRelay} and {@see \Hilos\Cluster\Placement\PlacementExecutor}
 * expose the worker server to the peer transport. A test supplies a fake so the executor runs
 * without a worker pool.
 *
 * Bringing the stopped agents back when the freeze lifts is the mirror seam
 * ({@see DaemonProtectedModeExecutor::enterInactive()}), landed in HIL-267 slice 7b.
 */
interface ProtectedModeAgentFreezer
{
    /**
     * Stops every agent this node hosts except the initiator; a no-op for agents not hosted here.
     *
     * @param ?string $initiatorAgentType Initiator agent type left running, or null when no initiator is recorded
     * @param ?string $initiatorAgentIndex Initiator agent index, or null for a singleton initiator
     */
    public function stopAgentsForProtectedMode(?string $initiatorAgentType, ?string $initiatorAgentIndex): void;
}
