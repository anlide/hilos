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
     * the answer comes from the agent type, index and constants — never from I/O. The answer is
     * the agent's project cost as it declares it; the coordinator adds the mandatory slot itself
     * ({@see ResourceProfile::forPlacement()}, HIL-447).
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @return ResourceProfile Project cost; empty when the agent adds nothing to its slot
     * @throws LogicException When the agent declares a negative cost or the reserved slot
     * @throws AgentDaemonCreationFailedException When the agent daemon cannot be built
     * @throws HilosException Whatever the project's agent-daemon factory raises
     */
    public function placementProfile(string $agentType, ?string $agentIndex): ResourceProfile;

    /**
     * Launches an agent of the given type on this node and returns its worker id.
     *
     * An agent waiting for a worker of its type to connect - monopolistic or regular - or for its
     * earlier stop report is accepted rather than refused. The answer is null until it is seated,
     * and the caller learns its worker from {@see placedWorkerId()} once there is one.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @return ?int Worker id the agent was placed on (negative = monopolistic, positive = regular), or
     *     null while the agent waits for a worker or an earlier stop report
     * @throws AgentDaemonCreationFailedException When the agent daemon cannot be built
     * @throws NoSuitableWorkerException When no suitable worker is available to host it
     * @throws AgentNotLinkedToWorkerException When the agent is neither linked nor waiting for a start
     * @throws HilosException Whatever the project's agent-daemon factory raises
     */
    public function executePlacement(string $agentType, ?string $agentIndex): ?int;

    /**
     * Returns the worker an agent accepted by {@see executePlacement()} was seated on.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @return ?int Worker id once the agent is seated, null while its start is still waiting
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
