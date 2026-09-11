<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode;

use Hilos\Cluster\Placement\PlacementExecutor;

/**
 * Local port the daemon uses to stop this node's application agents while protected mode holds.
 *
 * The graceful-shutdown half of the freeze: once {@see ClusterProtectedMode} decides this node
 * enters the freeze, {@see DaemonProtectedModeExecutor::enterActivating()} hands the initiator's
 * agent identity to this seam so the daemon can stop every agent it hosts except the one driving
 * the destructive operation. The worker server implements it by reusing its per-agent stop path,
 * mirroring how {@see ProtectedModeReadyRelay} and {@see PlacementExecutor}
 * expose the worker server to the peer transport.
 *
 * Its mirror, {@see resumeAgentsForProtectedMode()}, brings those same agents back when the freeze
 * lifts ({@see DaemonProtectedModeExecutor::enterInactive()}).
 */
interface ProtectedModeAgentFreezer
{
    /**
     * Stops every agent this node hosts except the initiator; a no-op for agents not hosted here.
     *
     * Remembers exactly which agents it stopped so {@see resumeAgentsForProtectedMode()} can bring
     * back the same set when the freeze lifts.
     *
     * @param string $initiatorAgentType Initiator agent type left running
     * @param ?string $initiatorAgentIndex Initiator agent index, or null for a singleton initiator
     */
    public function stopAgentsForProtectedMode(string $initiatorAgentType, ?string $initiatorAgentIndex): void;

    /**
     * Restarts the agents {@see stopAgentsForProtectedMode()} stopped for this freeze; the mirror
     * of the stop, invoked when the freeze lifts.
     *
     * Replays each remembered agent through the node's normal start path, whose own leadership and
     * worker gates drop any that no longer belong here. A no-op when no freeze stopped anything.
     */
    public function resumeAgentsForProtectedMode(): void;

    /**
     * Names the agents this node has asked for and not yet heard back about (HIL-1000).
     *
     * Asked before a freeze is entered, and by the same object that snapshots and replays the
     * roster on purpose: a start it asked for is not a fact until the worker says so, and a
     * freeze that lands in between deregisters an agent whose start report is already on the
     * wire. The master then meets that report with a roster that does not name it, kills the
     * agent as an orphan, and the resume - working from a snapshot taken at that same moment -
     * brings back a roster one agent shorter than the one it froze.
     *
     * @return list<string> Ids of agents registered here whose start has not been reported yet
     */
    public function agentsStillStarting(): array;
}
