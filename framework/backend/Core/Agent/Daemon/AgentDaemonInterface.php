<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Daemon;

use Hilos\BaseDTO;
use Hilos\Core\Agent\DTO\AgentMessageDTOInterface;
use Hilos\Core\Agent\DTO\MessageFromUserDTO;
use Hilos\Core\Agent\Exception\AgentNotLinkedToWorkerException;
use Hilos\Socket\Client\WorkerClient;

/**
 * AgentDaemonInterface - Interface for agent proxies running in daemon.
 *
 * Agent proxies run in daemon process and handle routing between
 * external connections (WebSocket, HTTP, etc.) and worker agents.
 *
 * setWorkerClient() is needed to:
 * - Know which WorkerClient connection to use for sending messages to worker
 * - Route messages from external sources (users) to the correct worker agent
 * - Receive messages from worker agent and forward them to external clients
 */
interface AgentDaemonInterface
{
    /**
     * Get agent type (for routing purposes)
     *
     * @return string Agent type (e.g., 'chat', 'user')
     */
    public function getType(): string;

    /**
     * Get agent index
     *
     * @return ?string Agent index (null if no index needed)
     */
    public function getIndex(): ?string;

    /**
     * Check if agent requires monopolistic worker process
     *
     * @return bool True if agent requires monopolistic worker, false otherwise
     */
    public function requiresMonopolisticProcess(): bool;

    /**
     * Whether this agent is a cluster-singleton that runs only on the leader node.
     *
     * A leader-only agent (truth source, monopolistic/singleton, startup service)
     * must exist on exactly one node cluster-wide, so {@see \Hilos\Socket\Server\WorkerServer::startAgent()}
     * refuses to start it on a node that is not the cluster leader. Standalone
     * daemons are always their own leader, so the flag has no effect off-cluster.
     *
     * The default is true (fail-safe leader-only): forgetting to mark an agent
     * under-runs it (safe) rather than double-running a truth source (a
     * correctness bug). Per-node agents opt out by returning false.
     *
     * @return bool True if the agent may run only on the cluster leader
     */
    public function requiresClusterLeadership(): bool;

    /**
     * Capability tags a node must advertise to host this agent.
     *
     * The cluster hard-constraint for placement: the leader refuses to place the agent on
     * a node whose advertised capabilities do not include every tag returned here (see
     * {@see \Hilos\Cluster\Placement\ClusterPlacement::placeAgentOnNode()}). The default is
     * an empty list — the agent runs anywhere. Soft preferences and choosing among several
     * fit nodes are node-selection policy (HIL-182), not this binary gate.
     *
     * @return list<string> Required capability tags; empty when the agent runs anywhere
     */
    public function requiredCapabilities(): array;

    /**
     * Set worker client connection
     *
     * WorkerClient represents the connection to the worker process where
     * the actual agent runs. This is needed to:
     * - Send messages from external sources (users) to worker agent
     * - Receive messages from worker agent and forward to external clients
     *
     * @param WorkerClient $workerClient Worker client connection
     */
    public function setWorkerClient(WorkerClient $workerClient): void;

    /**
     * Check whether the daemon is already linked to a worker client
     *
     * @return bool True when a worker client is attached
     */
    public function hasWorkerClient(): bool;

    /**
     * Get worker client connection
     *
     * @return WorkerClient Worker client connection
     * @throws AgentNotLinkedToWorkerException When the daemon is not yet linked to a worker
     */
    public function getWorkerClient(): WorkerClient;

    /**
     * Send message to worker agent (from external user)
     *
     * Routes message from external source (WebSocket, HTTP, etc.) to worker agent.
     *
     * @param BaseDTO $message Message DTO
     */
    public function sendToAgent(BaseDTO $message): void;

    /**
     * Send message from agent to user (external client)
     *
     * Routes message from worker agent to external client (WebSocket, HTTP, etc.).
     *
     * @param AgentMessageDTOInterface $message Message from agent
     */
    public function sendToUser(AgentMessageDTOInterface $message): void;

    /**
     * Handle message from worker agent
     *
     * Called when message arrives from worker agent. Should forward to user.
     *
     * @param AgentMessageDTOInterface $message Message from worker agent
     */
    public function handleMessageFromAgent(AgentMessageDTOInterface $message): void;

    /**
     * Handle message from external source (WebSocket, HTTP, etc.)
     *
     * Called when message arrives from external source. Should forward to worker agent.
     *
     * @param MessageFromUserDTO $message Message from external source
     * @return ?AgentMessageDTOInterface Response DTO (null if no response needed)
     */
    public function handleMessageFromUser(MessageFromUserDTO $message): ?AgentMessageDTOInterface;

    /**
     * Called when agent proxy is started
     *
     * Called once when agent proxy is created.
     */
    public function onStart(): void;

    /**
     * Called when agent proxy is stopped
     *
     * Called once when agent proxy is being destroyed.
     */
    public function onStop(): void;
}
