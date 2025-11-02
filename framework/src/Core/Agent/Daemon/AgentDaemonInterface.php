<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Daemon;

use Hilos\Utils\DTO\Agent\AgentMessageDTOInterface;
use Hilos\Utils\DTO\Agent\MessageFromUserDTO;
use Hilos\Socket\Client\WorkerClient;

/**
 * AgentDaemonInterface - Interface for agent proxies running in daemon
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
     * Get worker client connection
     *
     * @return ?WorkerClient Worker client or null if not set
     */
    public function getWorkerClient(): ?WorkerClient;

    /**
     * Send message to worker agent (from external user)
     *
     * Routes message from external source (WebSocket, HTTP, etc.) to worker agent.
     *
     * @param MessageFromUserDTO $message Message from user
     */
    public function sendToAgent(MessageFromUserDTO $message): void;

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
     * @return AgentMessageDTOInterface|null Response DTO (null if no response needed)
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

