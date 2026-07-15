<?php

declare(strict_types=1);

namespace Hilos\Cluster\Placement;

use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Agent\Exception\AgentNotLinkedToWorkerException;
use Hilos\Core\Agent\Exception\NoSuitableWorkerException;
use Hilos\Socket\Server\WorkerServer;

/**
 * Local port the placement coordinator uses to launch, stop, and describe agents on
 * this node.
 *
 * This is the seam between placement and the worker pool: the coordinator decides which
 * node an agent belongs on, this port carries out the local half. The worker server
 * implements it by reusing its existing {@see WorkerServer::startAgent()}
 * / stopAgent path — no new spawn logic — so a placed agent is hosted exactly like a
 * locally-started one. A test supplies a fake so the coordinator runs without a worker
 * pool.
 */
interface PlacementExecutor
{
    /**
     * Returns the capability tags an agent type requires to run, so the leader can
     * hard-check them against a candidate node before placing.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @return list<string> Required capability tags; empty when the agent runs anywhere
     * @throws AgentDaemonCreationFailedException When the agent daemon cannot be built
     */
    public function requiredCapabilities(string $agentType, ?string $agentIndex): array;

    /**
     * Launches an agent of the given type on this node and returns its worker id.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @return int Worker id the agent was placed on (negative = monopolistic, positive = regular)
     * @throws AgentDaemonCreationFailedException When the agent daemon cannot be built
     * @throws NoSuitableWorkerException When no suitable worker is available to host it
     * @throws AgentNotLinkedToWorkerException When the agent did not link to a worker
     */
    public function executePlacement(string $agentType, ?string $agentIndex): int;

    /**
     * Stops a placed agent on this node; a no-op when it is not running.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     */
    public function revokePlacement(string $agentType, ?string $agentIndex): void;
}
