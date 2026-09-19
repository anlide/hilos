<?php

declare(strict_types=1);

namespace Hilos\Cluster\Placement;

use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Agent\Exception\AgentNotLinkedToWorkerException;
use Hilos\Core\Agent\Exception\NoSuitableWorkerException;
use Hilos\HilosException;
use Hilos\Socket\Server\WorkerServer;
use LogicException;

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
     * @throws HilosException Whatever the project's agent-daemon factory raises
     */
    public function requiredCapabilities(string $agentType, ?string $agentIndex): array;

    /**
     * Returns the agent's resource cost, so the leader can reserve it against the node's declared
     * capacity and keep a node without free room out of the candidates (HIL-448).
     *
     * Called on the leader's master loop for every live placement each time a node is chosen, so
     * the answer comes from the agent type, index and constants — never from I/O.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @return ResourceProfile Resource cost; empty when the agent consumes nothing
     * @throws LogicException When the agent declares a negative cost
     * @throws AgentDaemonCreationFailedException When the agent daemon cannot be built
     * @throws HilosException Whatever the project's agent-daemon factory raises
     */
    public function placementProfile(string $agentType, ?string $agentIndex): ResourceProfile;

    /**
     * Launches an agent of the given type on this node and returns its worker id.
     *
     * A monopolistic agent that found no free worker is accepted rather than refused: a worker is
     * being raised for it, and the answer is null until it is seated (HIL-998). The caller learns
     * the worker from {@see placedWorkerId()} once there is one.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @return ?int Worker id the agent was placed on (negative = monopolistic, positive = regular), or
     *     null while the agent waits for a worker raised for it
     * @throws AgentDaemonCreationFailedException When the agent daemon cannot be built
     * @throws NoSuitableWorkerException When no suitable worker is available to host it
     * @throws AgentNotLinkedToWorkerException When the agent neither linked to a worker nor waits for one
     * @throws HilosException Whatever the project's agent-daemon factory raises
     */
    public function executePlacement(string $agentType, ?string $agentIndex): ?int;

    /**
     * Returns the worker an agent accepted by {@see executePlacement()} was seated on.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @return ?int Worker id once the agent is seated, null while it is still waiting for a worker
     */
    public function placedWorkerId(string $agentType, ?string $agentIndex): ?int;

    /**
     * Stops a placed agent on this node; a no-op when it is not running.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     */
    public function revokePlacement(string $agentType, ?string $agentIndex): void;
}
