<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Daemon;

use Hilos\BaseDTO;
use Hilos\Cluster\Placement\ClusterPlacement;
use Hilos\Cluster\Placement\ResourceProfile;
use Hilos\Core\Agent\DTO\AgentMessageDTOInterface;
use Hilos\Core\Agent\DTO\MessageFromUserDTO;
use Hilos\Core\Agent\Exception\AgentNotLinkedToWorkerException;
use Hilos\Socket\Client\WorkerClient;
use LogicException;

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
     * Capability tags a node must advertise to host this agent.
     *
     * The cluster hard-constraint for placement: the leader refuses to place the agent on
     * a node whose advertised capabilities do not include every tag returned here (see
     * {@see ClusterPlacement::placeAgentOnNode()}). The default is
     * an empty list — the agent runs anywhere. This binary tag gate is the boolean half of
     * placement; the consumable half — what the agent costs a node — is
     * {@see placementProfile()}.
     *
     * @return list<string> Required capability tags; empty when the agent runs anywhere
     */
    public function requiredCapabilities(): array;

    /**
     * The agent's resource cost: how much of each declared node capacity it consumes (HIL-448).
     *
     * The consumable half of resource-aware placement layered on the boolean
     * {@see requiredCapabilities()} gate. The cost is a reservation the leader subtracts from the
     * node's declared capacity while the agent's placement lives, and a node without free room for
     * it is not a candidate. The leader reads it on its master loop every time it chooses a node,
     * so compute it from the agent type, index and constants only — no database, file or network
     * I/O. The default is {@see ResourceProfile::none()}: the agent consumes nothing and is spread
     * over the nodes by head count.
     *
     * @return ResourceProfile Resource cost; empty when the agent consumes nothing
     * @throws LogicException When the declared cost is negative ({@see ResourceProfile::costs()})
     */
    public function placementProfile(): ResourceProfile;

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
